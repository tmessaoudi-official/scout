<?php

declare(strict_types=1);

namespace Scout\Job;

/**
 * Hard disqualifiers and score are two different mechanisms (hard rule 8). H1–H8 reject and are
 * logged; the six components score out of the FULL 100 and never reject; RED subtracts, capped,
 * outside the 100 — a RED signal names itself and never hides an offer.
 *
 * Every disqualifier fires on a STATED fact only. Unknown pay, an unstated mode, an unrecognised
 * place and an empty contract set reject nothing. Pay rejects only when EVERY stated line is
 * comparable and under its floor (N2): a net figure, a package (N1) and a portage salary (N6) are
 * never compared, so their presence keeps the offer.
 *
 * An unknown component scores 0 and SAYS so (`… — hors score`), exactly as on the car side: a
 * missing line on a phone reads as a good value.
 */
final class JobScorer
{
    public function judge(JobListing $offer, JobFacts $facts, JobCriteria $criteria, \DateTimeImmutable $now): JobVerdict
    {
        $reject = self::disqualifier($offer, $facts, $criteria);
        if ($reject !== null) {
            return JobVerdict::rejected([$reject]);
        }

        $w = $criteria->weights;
        $text = JobText::surface($offer->title . "\n" . $offer->description);
        $score = 0.0;
        $reasons = [];

        [$share, $reasons[]] = self::stack($text, $criteria);
        $score += $w['stack'] * $share;

        [$share, $reasons[]] = self::pay($facts->pay, $criteria);
        $score += $w['pay'] * $share;

        $level = $facts->level ?? 'unlabelled';
        $score += $w['level'] * $criteria->levelShares[$level];
        $reasons[] = $facts->level === null ? 'intitulé sans niveau' : 'niveau : ' . $facts->level;

        [$share, $reasons[]] = self::green($text, $criteria);
        $score += $w['green'] * $share;

        [$share, $reasons[]] = self::remote($facts, $text, $criteria);
        $score += $w['remote'] * $share;

        [$share, $reasons[]] = self::freshness($offer->publishedAt, $now, $criteria->freshnessPeakDays);
        $score += $w['freshness'] * $share;

        $red = $criteria->red->hits($text, true);
        if ($red !== []) {
            $penalty = min($criteria->redCap, $criteria->redPenalty * count($red));
            $score -= $penalty;
            $reasons[] = sprintf('signaux rouges : %s (−%d)', implode(', ', $red), $penalty);
        }

        return JobVerdict::matched((int) max(0, min(100, round($score))), $reasons);
    }

    private static function disqualifier(JobListing $offer, JobFacts $facts, JobCriteria $criteria): ?string
    {
        if ($facts->unreadable !== null) {
            return $facts->unreadable;
        }

        $under = self::payUnderFloor($facts, $criteria);
        if ($under !== null) {
            return $under;
        }

        if ($facts->contracts !== [] && array_diff($facts->contracts, $criteria->rejectedContracts) === []) {
            return 'contrat hors périmètre : ' . implode(', ', $facts->contracts);
        }

        $pattern = $criteria->excludedBy($offer->title . "\n" . $offer->description);
        if ($pattern !== null) {
            return 'motif exclu : ' . $pattern;
        }

        if (!$criteria->passesRoleGate($offer->title)) {
            return 'intitulé hors métier : ' . $offer->title;
        }

        $label = $criteria->titleRejectedBy($offer->title);
        if ($label !== null) {
            return 'intitulé exclu : ' . $label;
        }

        if (!$criteria->frenchNationality && $facts->eligibility !== null) {
            return $facts->eligibility;
        }

        if ($criteria->locationClass($offer->location) === JobCriteria::OUTSIDE && in_array($facts->workMode, ['onsite', 'hybrid'], true)) {
            return 'lieu hors Île-de-France (' . trim($offer->location) . ') et travail ' . ($facts->workMode === 'onsite' ? 'sur site' : 'hybride');
        }

        return null;
    }

    /** H1 and H2 together, because N2 reads them as one set: every stated line must be comparable AND under. */
    private static function payUnderFloor(JobFacts $facts, JobCriteria $criteria): ?string
    {
        if ($facts->pay === []) {
            return null;
        }
        $portageOnly = in_array('portage', $facts->contracts, true) && !in_array('cdi', $facts->contracts, true);
        $lines = [];
        foreach ($facts->pay as $line) {
            if ($line->kind === PayLine::TJM) {
                if ($line->maxEur >= $criteria->tjmFloorEur) {
                    return null;
                }
                $lines[] = sprintf('TJM %s € sous le plancher de %s €', self::n($line->maxEur), self::n($criteria->tjmFloorEur));

                continue;
            }
            $annual = $line->annualMaxForFloor();
            if ($line->basis !== PayLine::GROSS || $portageOnly || $annual === null || $annual >= $criteria->salaryFloorEur) {
                return null;
            }
            $lines[] = sprintf('salaire %s € brut par an sous le plancher de %s €', self::n($annual), self::n($criteria->salaryFloorEur));
        }

        return implode(' ; ', $lines);
    }

    /** @return array{float, string} */
    private static function stack(string $text, JobCriteria $criteria): array
    {
        $back = $criteria->backStack->hits($text);
        $front = $criteria->frontStack->hits($text);
        $adjacent = $back === [] ? $criteria->adjacentStack->hits($text) : [];
        if ($back === [] && $front === [] && $adjacent === []) {
            $other = $criteria->otherStack->hits($text);

            return [0.0, $other === [] ? 'stack inconnue — hors score' : implode(', ', $other) . ' — stack hors préférences'];
        }
        $share = ($back !== [] ? $criteria->backShare : ($adjacent !== [] ? $criteria->adjacentShare : 0.0))
            + ($front !== [] ? $criteria->frontShare : 0.0);

        return [$share, 'stack : ' . implode(', ', [...$back, ...$adjacent, ...$front])];
    }

    /**
     * The best share across the comparable lines, on the upper bound: the floor earns 0, the target the
     * whole share. A net or package figure is shown and never compared.
     *
     * @param list<PayLine> $pay
     *
     * @return array{float, string}
     */
    private static function pay(array $pay, JobCriteria $criteria): array
    {
        if ($pay === []) {
            return [0.0, 'rémunération inconnue — hors score'];
        }
        $best = null;
        $shown = [];
        foreach ($pay as $line) {
            if ($line->kind === PayLine::TJM) {
                $share = ($line->maxEur - $criteria->tjmFloorEur) / ($criteria->tjmTargetEur - $criteria->tjmFloorEur);
                $shown[] = sprintf('TJM jusqu’à %s €', self::n($line->maxEur));
            } elseif ($line->basis === PayLine::GROSS && $line->annualMaxForScore() !== null) {
                $share = ($line->annualMaxForScore() - $criteria->salaryFloorEur) / ($criteria->salaryTargetEur - $criteria->salaryFloorEur);
                $shown[] = sprintf('jusqu’à %s € brut par an', self::n($line->annualMaxForScore()));
            } else {
                $shown[] = sprintf('%s (%s) non comparable — hors score', $line->text, $line->basis);

                continue;
            }
            $best = max($best ?? 0.0, max(0.0, min(1.0, $share)));
        }

        return [$best ?? 0.0, 'rémunération : ' . implode(' ; ', $shown)];
    }

    /** @return array{float, string} */
    private static function green(string $text, JobCriteria $criteria): array
    {
        if ($criteria->green === []) {
            return [0.0, 'signaux verts — aucun configuré'];
        }
        $fired = [];
        foreach ($criteria->green as $group => $terms) {
            $hits = $terms->hits($text, true);
            if ($hits !== []) {
                $fired[] = $group . ' (' . implode(', ', $hits) . ')';
            }
        }

        return [count($fired) / count($criteria->green), $fired === [] ? 'aucun signal vert' : 'signaux verts : ' . implode(' ; ', $fired)];
    }

    /** @return array{float, string} */
    private static function remote(JobFacts $facts, string $text, JobCriteria $criteria): array
    {
        if ($facts->workMode === null) {
            return [0.0, 'mode de travail inconnu — hors score'];
        }
        [$share, $line] = match (true) {
            $facts->workMode === 'remote' => [$criteria->remoteDaysShare[5], 'télétravail complet'],
            $facts->workMode === 'onsite' => [$criteria->remoteDaysShare[0], 'sur site'],
            $facts->remoteDays !== null => [$criteria->remoteDaysShare[(int) round($facts->remoteDays)], sprintf('hybride — %d jour(s) de télétravail', (int) round($facts->remoteDays))],
            default => [$criteria->hybridUnstatedShare, 'hybride — jours non précisés'],
        };
        $conditions = $criteria->conditions->hits($text, true);
        if ($conditions !== []) {
            $share = min(1.0, $share + $criteria->conditionsBonus);
            $line .= ' · ' . implode(', ', $conditions);
        }

        return [$share, $line];
    }

    /** @return array{float, string} */
    private static function freshness(?string $publishedAt, \DateTimeImmutable $now, int $peakDays): array
    {
        $published = self::instant($publishedAt);
        if ($published === null) {
            return [0.0, 'date de publication inconnue — hors score'];
        }
        $days = max(0.0, ($now->getTimestamp() - $published->getTimestamp()) / 86400);
        $share = $days <= $peakDays ? 1.0 : max(0.0, 1 - ($days - $peakDays) / $peakDays);

        return [$share, sprintf('publiée il y a %d jour(s)', (int) floor($days))];
    }

    /**
     * A STRICT ISO-8601 instant, by round-trip: `new \DateTimeImmutable` is a relative-expression parser
     * that reads `hier` or `tomorrow` as a date and moves it, which would close the freshness window.
     */
    private static function instant(?string $iso): ?\DateTimeImmutable
    {
        if ($iso === null) {
            return null;
        }
        $normalised = preg_replace('~Z$~', '+00:00', trim($iso));
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', (string) $normalised);

        return $parsed !== false && $parsed->format('Y-m-d\TH:i:sP') === $normalised ? $parsed : null;
    }

    private static function n(int $v): string
    {
        return number_format($v, 0, ',', ' ');
    }
}
