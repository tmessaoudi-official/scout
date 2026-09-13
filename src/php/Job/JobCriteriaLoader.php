<?php

declare(strict_types=1);

namespace Scout\Job;

use Scout\Config\ConfigError;
use Scout\Config\Reader;

/**
 * `config/job/criteria.json`, read with the strict `Reader`: every unknown key is a hard error,
 * `_`-prefixed keys are comments, and a gitignored `criteria.local.json` beside it overrides field by
 * field.
 *
 * Every refusal here is a shape that would otherwise MISBEHAVE SILENTLY: weights that do not sum to
 * 100 rescale every score, an empty role gate rejects every offer, a pattern that does not compile
 * matches nothing (`@preg_match` neither warns nor throws), a target at or under its floor divides the
 * pay curve by zero, and a rejected contract the classifier never emits is a rule that can never fire.
 */
final class JobCriteriaLoader
{
    private const float SHARE_EPSILON = 0.0001;

    public static function load(string $path, ?string $localPath = null): JobCriteria
    {
        $data = self::decodeObject($path);
        if ($localPath !== null && is_file($localPath)) {
            $data = self::deepMerge($data, self::decodeObject($localPath));
        }

        return self::fromArray($data, basename($path));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws ConfigError
     */
    public static function fromArray(array $data, string $pointer = 'job/criteria.json'): JobCriteria
    {
        $r = new Reader($pointer, $data);

        $roleWords = self::fromList($r->requireStringList('role_words'), $pointer . '.role_words');

        $titleRejects = [];
        $tr = $r->requireObject('title_rejects');
        foreach ($tr->keys() as $label) {
            if (str_starts_with($label, '_')) {
                continue;
            }
            $titleRejects[$label] = self::fromList($tr->requireStringList($label), $pointer . '.title_rejects.' . $label);
        }
        $tr->done();

        $exclude = self::fromList($r->requireStringList('exclude_patterns', allowEmptyList: true), $pointer . '.exclude_patterns');

        $c = $r->requireObject('contracts');
        $rejected = $c->requireStringList('rejected', allowEmptyList: true);
        foreach ($rejected as $i => $contract) {
            if (!in_array($contract, JobFacts::CONTRACTS, true)) {
                throw ConfigError::at($pointer . '.contracts.rejected[' . $i . ']', 'un mot de contrat que le classifieur émet est attendu (' . implode(', ', JobFacts::CONTRACTS) . '), reçu « ' . $contract . ' »');
            }
        }
        $c->done();

        $p = $r->requireObject('pay');
        $salaryFloor = $p->requireInt('salary_floor_eur', 1);
        $salaryTarget = $p->requireInt('salary_target_eur', 1);
        $tjmFloor = $p->requireInt('tjm_floor_eur', 1);
        $tjmTarget = $p->requireInt('tjm_target_eur', 1);
        $p->done();
        if ($salaryTarget <= $salaryFloor) {
            throw ConfigError::at($pointer . '.pay.salary_target_eur', 'une cible strictement au-dessus du plancher (' . $salaryFloor . ') est attendue');
        }
        if ($tjmTarget <= $tjmFloor) {
            throw ConfigError::at($pointer . '.pay.tjm_target_eur', 'une cible strictement au-dessus du plancher (' . $tjmFloor . ') est attendue');
        }

        $e = $r->requireObject('eligibility');
        $french = $e->requireBool('french_nationality');
        $e->done();

        $l = $r->requireObject('location');
        $idf = self::fromList($l->requireStringList('idf_patterns', allowEmptyList: true), $pointer . '.location.idf_patterns');
        $outside = self::fromList($l->requireStringList('outside_patterns', allowEmptyList: true), $pointer . '.location.outside_patterns');
        $l->done();

        $s = $r->requireObject('stack');
        [$backShare, $back] = self::group($s->requireObject('back'), $pointer . '.stack.back');
        [$frontShare, $front] = self::group($s->requireObject('front'), $pointer . '.stack.front');
        [$adjacentShare, $adjacent] = self::group($s->requireObject('adjacent'), $pointer . '.stack.adjacent');
        $other = self::fromMap($s->takeRaw('other'), $pointer . '.stack.other');
        $s->done();
        if (abs($backShare + $frontShare - 1.0) > self::SHARE_EPSILON) {
            throw ConfigError::at($pointer . '.stack', 'back.share + front.share = 1 est attendu, reçu ' . ($backShare + $frontShare));
        }
        if ($adjacentShare > $backShare) {
            throw ConfigError::at($pointer . '.stack.adjacent.share', 'une part au plus égale à back.share (' . $backShare . ') est attendue');
        }

        $green = [];
        $g = $r->requireObject('green');
        foreach ($g->keys() as $group) {
            if (str_starts_with($group, '_')) {
                continue;
            }
            $green[$group] = self::fromMap($g->takeRaw($group), $pointer . '.green.' . $group);
        }
        $g->done();

        $red = $r->requireObject('red');
        $redPenalty = $red->requireInt('penalty', 0, 100);
        $redCap = $red->requireInt('cap', 0, 100);
        $redTerms = self::fromMap($red->takeRaw('terms'), $pointer . '.red.terms');
        $red->done();
        if ($redCap < $redPenalty) {
            throw ConfigError::at($pointer . '.red.cap', 'un plafond au moins égal à une pénalité (' . $redPenalty . ') est attendu');
        }

        $rm = $r->requireObject('remote');
        $daysShare = self::daysShare($rm->takeRaw('days_share'), $pointer . '.remote.days_share');
        $hybridUnstated = $rm->requireFloat('hybrid_unstated_share', 0.0, 1.0);
        $conditionsBonus = $rm->requireFloat('conditions_bonus', 0.0, 1.0);
        $conditions = self::fromMap($rm->takeRaw('conditions'), $pointer . '.remote.conditions');
        $rm->done();

        $lv = $r->requireObject('level');
        $levels = [];
        foreach ([JobFacts::LEAD, JobFacts::SENIOR, JobFacts::CONFIRMED, JobFacts::JUNIOR, 'unlabelled'] as $level) {
            $levels[$level] = $lv->requireFloat($level, 0.0, 1.0);
        }
        $lv->done();

        $f = $r->requireObject('freshness');
        $peakDays = $f->requireInt('peak_days', 1, 365);
        $f->done();

        $w = $r->requireObject('weights');
        $weights = [];
        foreach (JobCriteria::COMPONENTS as $component) {
            $weights[$component] = $w->requireInt($component, 0, 100);
        }
        $w->done();
        if (array_sum($weights) !== 100) {
            throw ConfigError::at($pointer . '.weights', 'des poids de somme 100 sont attendus, reçu ' . array_sum($weights));
        }

        $n = $r->requireObject('notify');
        $notify = new JobNotifyPolicy(
            channels: $n->requireStringList('channels'),
            sourceAlertCooldownHours: $n->optInt('source_alert_cooldown_hours', 12, 1, 720) ?? 12,
            pushMinScore: $n->optInt('push_min_score', null, 0, 100),
            rollupHour: $n->optInt('rollup_hour', null, 0, 23),
        );
        $n->done();

        $r->done();

        return new JobCriteria(
            roleWords: $roleWords, titleRejects: $titleRejects, excludePatterns: $exclude, rejectedContracts: $rejected,
            salaryFloorEur: $salaryFloor, salaryTargetEur: $salaryTarget, tjmFloorEur: $tjmFloor, tjmTargetEur: $tjmTarget,
            frenchNationality: $french, idfPlaces: $idf, outsidePlaces: $outside,
            backShare: $backShare, backStack: $back, frontShare: $frontShare, frontStack: $front,
            adjacentShare: $adjacentShare, adjacentStack: $adjacent, otherStack: $other,
            green: $green, redPenalty: $redPenalty, redCap: $redCap, red: $redTerms,
            remoteDaysShare: $daysShare, hybridUnstatedShare: $hybridUnstated, conditionsBonus: $conditionsBonus, conditions: $conditions,
            levelShares: $levels, freshnessPeakDays: $peakDays, weights: $weights, notify: $notify,
        );
    }

    /**
     * A list of patterns, each its own label.
     *
     * @param list<string> $patterns
     */
    private static function fromList(array $patterns, string $at): JobTerms
    {
        $out = [];
        foreach ($patterns as $i => $pattern) {
            self::compiles($pattern, $at . '[' . $i . ']');
            $out[$pattern] = $pattern;
        }

        return new JobTerms($out);
    }

    /** An object of label => pattern. `{}` decodes to `[]` and is an empty vocabulary. */
    private static function fromMap(mixed $raw, string $at): JobTerms
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw ConfigError::at($at, 'un objet libellé => motif est attendu');
        }
        $out = [];
        foreach ($raw as $label => $pattern) {
            if (str_starts_with((string) $label, '_')) {
                continue;
            }
            if (!is_string($pattern) || trim($pattern) === '') {
                throw ConfigError::at($at . '.' . $label, 'un motif non vide est attendu');
            }
            self::compiles($pattern, $at . '.' . $label);
            $out[(string) $label] = $pattern;
        }

        return new JobTerms($out);
    }

    /** A pattern that does not compile matches nothing and says nothing, so it is refused here. */
    private static function compiles(string $fragment, string $at): void
    {
        if (@preg_match(JobTerms::regex($fragment), '') === false) {
            throw ConfigError::at($at, 'un motif qui compile est attendu : ' . preg_last_error_msg());
        }
    }

    /** @return array{float, JobTerms} */
    private static function group(Reader $group, string $at): array
    {
        $share = $group->requireFloat('share', 0.0, 1.0);
        $terms = self::fromMap($group->takeRaw('terms'), $at . '.terms');
        $group->done();

        return [$share, $terms];
    }

    /**
     * `{"0": …, "5": …}` decodes to a PHP LIST, so `Reader::requireObject()` cannot read it; the six
     * days are checked by hand — every day present, nothing else, each a share.
     *
     * @return array<int, float>
     */
    private static function daysShare(mixed $raw, string $at): array
    {
        if (!is_array($raw)) {
            throw ConfigError::at($at, 'un objet des jours 0 à 5 est attendu');
        }
        $out = [];
        for ($day = 0; $day <= 5; $day++) {
            $value = $raw[$day] ?? null;
            if (!is_int($value) && !is_float($value)) {
                throw ConfigError::at($at . '.' . $day, 'une part entre 0 et 1 est attendue pour chaque jour de 0 à 5');
            }
            if ($value < 0 || $value > 1) {
                throw ConfigError::at($at . '.' . $day, 'une part entre 0 et 1 est attendue, reçu ' . $value);
            }
            $out[$day] = (float) $value;
        }
        $extra = array_diff(array_map('strval', array_keys($raw)), ['0', '1', '2', '3', '4', '5']);
        if ($extra !== []) {
            throw ConfigError::at($at, 'clé(s) inconnue(s) ' . implode(', ', $extra));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function decodeObject(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw ConfigError::at(basename($path), 'fichier illisible : ' . $path);
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ConfigError::at(basename($path), 'JSON invalide : ' . $e->getMessage());
        }
        if (!is_array($data)) {
            throw ConfigError::at(basename($path), 'un objet JSON est attendu à la racine');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private static function deepMerge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            $base[$k] = is_array($v) && is_array($base[$k] ?? null) && !array_is_list($v) ? self::deepMerge($base[$k], $v) : $v;
        }

        return $base;
    }
}
