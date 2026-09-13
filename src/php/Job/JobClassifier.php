<?php

declare(strict_types=1);

namespace Scout\Job;

use Scout\Core\MalformedText;
use Scout\Core\Text;

/**
 * Reads what an offer STATES — contracts, work mode, title level, pay, an eligibility clause — and
 * forms no verdict. Every rule the developer can move lives in `config/job/criteria.json` and is
 * applied by `JobScorer`; what is here is only READING, and reading has three disciplines, each paid
 * for on the rent and car sides first:
 *
 * - **The negation is read first.** `pas de freelance`, `nationalité française non requise`, `sans
 *   habilitation` are ordinary copy, and read bare each one states the opposite of what the ad says.
 * - **A word counts only on the surface it belongs to.** `stage` and `alternance` are contracts in a
 *   TITLE; in a description they are `encadrement de stagiaires` and `nous accueillons des
 *   alternants`, and `étage` folds to a word that contains `stage`. The title level is read from the
 *   title only — `vous serez lead sur le projet` describes a task, not a grade.
 * - **Silence is unknown, never a value** (hard rule 9). Unmentioned remote is not on site; no stated
 *   contract is not "no contract in scope"; an unlabelled title is its own level, not junior.
 *
 * `.claude/hooks/tenure-guard.sh` watches the rent and car excluded sets and does not cover this domain, on
 * purpose: nothing here is a non-overridable eligibility set. The one eligibility rule (H7) is a
 * switch the developer flips, and it lives in config.
 */
final class JobClassifier
{
    /** Contract words read in a TITLE. `mission` alone is deliberately absent: `vos missions` is a task list. */
    private const array TITLE_CONTRACTS = [
        'alternance' => '~\balternances?\b|\balternant(?:e|s|es)?\b|\bapprenti(?:e|s|es)?\b|\bapprentissage\b(?! automatique)|\bwork[- ]study\b|\bcontrat de professionnalisation\b~u',
        'cdd' => '~\bcdd\b~u',
        'cdi' => '~\bcdi\b~u',
        'freelance' => '~\bfree[- ]?lances?\b|\bfreelancers?\b~u',
        'interim' => '~\binterim\b|\bcontrat de mission\b|\btravail temporaire\b~u',
        'portage' => '~\bportage\b~u',
        'stage' => '~\bstages?\b|\bstagiaires?\b|\binternships?\b|\bintern\b~u',
        // `vie` is a French word (`qualité de vie`); only the dotted form and the full name are a VIE.
        'vie' => '~\bv\.i\.e\b|\bvolontariat international\b~u',
    ];

    /** Contract words read in a DESCRIPTION — never stage or alternance (see the class docblock). */
    private const array DESCRIPTION_CONTRACTS = ['cdd', 'cdi', 'freelance', 'interim', 'portage', 'vie'];

    private const string REMOTE = '~\bfull[- ]?remote\b|\b100[ ]?%[ ]?(?:en )?(?:remote|teletravail|a distance)\b|\bteletravail (?:complet|total|integral)\b|\bremote[- ]first\b|\bfully remote\b~u';

    /** A bare `remote` only states a mode in a TITLE — in a description it is `remote teams`. */
    private const string TITLE_REMOTE = '~\bremote\b|\ba distance\b~u';

    private const string HYBRID = '~\bhybrides?\b|\bteletravail partiel\b|\bremote partiel\b|\bpartial(?:ly)? remote\b~u';

    /** On-site statements carry their own negation, so they are matched literally, never negation-checked. */
    private const string ONSITE = '~\bpas de teletravail\b|\bsans teletravail\b|\bteletravail (?:non possible|impossible|non autorise)\b|\b100[ ]?%[ ]?(?:presentiel|sur site)\b|\bsur site uniquement\b|\bpresentiel (?:obligatoire|uniquement)\b|\bno remote\b|\bon[- ]site only\b~u';

    private const string DAYS = '([1-5]|une?|deux|trois|quatre|cinq)[ ]*(?:j|jours?|days?)\b[ ]*(?:par semaine[ ]+|/[ ]*semaine[ ]+|per week[ ]+|a la semaine[ ]+)?';

    private const string REMOTE_PLACE = '(?:de[ ]+|en[ ]+|of[ ]+)?(?:teletravail|remote|a distance|distanciel|home office|work from home)\b';

    private const string ONSITE_PLACE = '(?:sur site|au bureau|en presentiel|on[- ]site|in (?:the )?office|dans nos locaux)\b';

    private const array WORDS = ['un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5];

    /** Title levels, highest first: the first that fires wins. */
    private const array LEVELS = [
        JobFacts::LEAD => '~\bleads?\b|\btech[- ]?lead\b|\bstaff\b|\bprincipal\b|\bhead of\b|\barchitecte?s?\b|\bcto\b|\bengineering manager\b~u',
        JobFacts::SENIOR => '~\bseniors?\b|\bsr\b|\bexperts?\b~u',
        JobFacts::CONFIRMED => '~\bconfirme(?:e|s|es)?\b|\bmid[- ]?level\b|\bintermediate\b~u',
        JobFacts::JUNIOR => '~\bjuniors?\b|\bjr\b|\bdebutant(?:e|s|es)?\b|\bentry[- ]level\b|\bgraduates?\b|\bjeunes? diplome(?:e|s|es)?\b~u',
    ];

    private const string NATIONALITY = '~\bnationalite francaise\b~u';

    /** Anchored on the defence levels: a bare `habilitation` is access-rights management in business software. */
    private const string CLEARANCE = '~\bhabilitations? (?:au |de )?(?:niveau )?(?:secret|confidentiel|tres secret)(?:[- ]defense)?\b|\bhabilitables?\b|\bhabilitations? defense\b|\beligible a (?:une |l[\'’])?habilitation\b|\bsecurity clearance\b~u';

    public function read(JobListing $listing): JobFacts
    {
        try {
            $title = JobText::surface($listing->title);
            $description = JobText::surface($listing->description);
            Text::fold($listing->payText ?? '');
        } catch (MalformedText $e) {
            return new JobFacts(unreadable: 'texte illisible : ' . $e->getMessage());
        }
        $all = $title . "\n" . $description;

        [$mode, $days] = self::workMode($listing->workMode, $title, $all);

        return new JobFacts(
            contracts: self::contracts($listing->contracts, $title, $description),
            workMode: $mode,
            remoteDays: $days,
            level: self::level($title),
            pay: self::pay($listing),
            eligibility: match (true) {
                JobText::stated($all, self::NATIONALITY) => JobFacts::FRENCH_NATIONALITY,
                JobText::stated($all, self::CLEARANCE) => JobFacts::CLEARANCE,
                default => null,
            },
        );
    }

    /**
     * @param list<string> $field
     *
     * @return list<string>
     */
    private static function contracts(array $field, string $title, string $description): array
    {
        $found = [];
        foreach ($field as $value) {
            $folded = trim(Text::fold($value));
            $hits = array_keys(array_filter(self::TITLE_CONTRACTS, static fn (string $p): bool => preg_match($p, $folded) === 1));
            // An unrecognised structured value is KEPT as stated (N5: intérim, VIE, contractuel are shown, not rejected).
            foreach ($hits === [] && $folded !== '' ? [$folded] : $hits as $contract) {
                $found[$contract] = true;
            }
        }
        foreach (self::TITLE_CONTRACTS as $contract => $pattern) {
            if (JobText::stated($title, $pattern)) {
                $found[$contract] = true;
            }
        }
        foreach (self::DESCRIPTION_CONTRACTS as $contract) {
            if (JobText::stated($description, self::TITLE_CONTRACTS[$contract])) {
                $found[$contract] = true;
            }
        }

        $out = array_keys($found);
        sort($out);

        return $out;
    }

    /** @return array{?string, ?float} */
    private static function workMode(?string $field, string $title, string $all): array
    {
        $days = self::remoteDays($all);

        if ($field !== null) {
            return match ($field) {
                'remote' => ['remote', 5.0],
                'onsite' => ['onsite', 0.0],
                default => ['hybrid', $days !== null && $days > 0 && $days < 5 ? $days : null],
            };
        }

        $modes = [];
        if (JobText::stated($all, self::REMOTE) || JobText::stated($title, self::TITLE_REMOTE)) {
            $modes['remote'] = true;
        }
        if (JobText::stated($all, self::HYBRID)) {
            $modes['hybrid'] = true;
        }
        if (preg_match(self::ONSITE, $all) === 1) {
            $modes['onsite'] = true;
        }
        if ($days !== null) {
            $modes[$days >= 5 ? 'remote' : ($days <= 0 ? 'onsite' : 'hybrid')] = true;
        }

        // Two modes that cannot both be true state nothing — an unread mode is safer than a guessed one.
        if (isset($modes['onsite']) && count($modes) > 1) {
            return [null, null];
        }
        if (isset($modes['remote'], $modes['hybrid'])) {
            return $days !== null ? [$days >= 5 ? 'remote' : 'hybrid', $days] : [null, null];
        }

        return match (true) {
            isset($modes['remote']) => ['remote', 5.0],
            isset($modes['hybrid']) => ['hybrid', $days],
            isset($modes['onsite']) => ['onsite', 0.0],
            default => [null, null],
        };
    }

    private static function remoteDays(string $text): ?float
    {
        if (preg_match('~\b' . self::DAYS . self::REMOTE_PLACE . '~u', $text, $m) === 1
            || preg_match('~\b(?:teletravail|remote)[ ]*:?[ ]*(?:jusqu[\'’]a[ ]+|up to[ ]+)?([1-5]|une?|deux|trois|quatre|cinq)[ ]*(?:j|jours?|days?)\b~u', $text, $m) === 1) {
            return (float) self::count($m[1]);
        }
        if (preg_match('~\b' . self::DAYS . self::ONSITE_PLACE . '~u', $text, $m) === 1) {
            return (float) (5 - self::count($m[1]));
        }

        return null;
    }

    private static function count(string $word): int
    {
        return self::WORDS[$word] ?? (int) $word;
    }

    private static function level(string $title): ?string
    {
        foreach (self::LEVELS as $level => $pattern) {
            if (preg_match($pattern, $title) === 1) {
                return $level;
            }
        }

        return null;
    }

    /** @return list<PayLine> */
    private static function pay(JobListing $listing): array
    {
        $lines = [];
        if ($listing->salaryMinEur !== null || $listing->salaryMaxEur !== null) {
            $min = $listing->salaryMinEur ?? $listing->salaryMaxEur;
            $max = $listing->salaryMaxEur ?? $listing->salaryMinEur;
            $lines[] = new PayLine(PayLine::SALARY, PayLine::GROSS, PayLine::YEAR, (int) min($min, $max), (int) max($min, $max), 'salaire (champ de la source)');
        }
        if ($listing->tjmMinEur !== null || $listing->tjmMaxEur !== null) {
            $min = $listing->tjmMinEur ?? $listing->tjmMaxEur;
            $max = $listing->tjmMaxEur ?? $listing->tjmMinEur;
            $lines[] = new PayLine(PayLine::TJM, PayLine::HT, PayLine::DAY, (int) min($min, $max), (int) max($min, $max), 'TJM (champ de la source)');
        }

        $keyed = [];
        foreach ([...$lines, ...JobPay::read($listing->title . "\n" . ($listing->payText ?? '') . "\n" . $listing->description)] as $line) {
            $keyed[$line->key()] ??= $line;
        }

        return array_values($keyed);
    }
}
