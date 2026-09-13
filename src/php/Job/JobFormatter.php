<?php

declare(strict_types=1);

namespace Scout\Job;

use Scout\Core\Notify\Notification;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Priority;
use Scout\Core\SourceHealth;
use Scout\Rent\Notify\Formatter;

/**
 * What a job push says. The SOURCE leads every title (developer ruling, 2026-08-29), then the score,
 * then title · company · place · work mode — the facts a phone shows first.
 *
 * **Every push is NORMAL priority.** The rent and car `!!` marker rests on a calibrated score bar, and
 * the job weights are re-measured after a week of real rows (ruling 2026-09-13 15:37). A HIGH priority
 * chosen before that week would be tuned to nothing.
 *
 * Health and recovery notices are the rent formatter's, unchanged, exactly as the car formatter uses
 * them: a broken source is a broken source in every domain.
 */
final readonly class JobFormatter
{
    /** The canonical work modes (`JobListing::WORK_MODES`), in the words the alert itself uses. */
    private const array MODE_LABELS = ['remote' => 'télétravail', 'hybrid' => 'hybride', 'onsite' => 'sur site'];

    public function __construct(private Formatter $shared = new Formatter()) {}

    public function match(JobListing $offer, JobVerdict $verdict): Notification
    {
        return new Notification(
            kind: NotificationKind::MATCH,
            priority: Priority::NORMAL,
            title: $this->headline($offer, $verdict->score),
            reasons: $verdict->reasons,
            url: $offer->url,
            score: $verdict->score,
            sourceName: $offer->sourceName,
        );
    }

    /**
     * The daily ROLLUP: matches held back by `push_min_score`, one line each, the score leading so the
     * reader can skim. Not a digest — this domain has no doubt bin.
     *
     * @param list<array{offer: JobListing, score: ?int}> $entries
     */
    public function rollup(array $entries): Notification
    {
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = '• ' . $this->headline($entry['offer'], $entry['score']);
        }

        return new Notification(
            kind: NotificationKind::ROLLUP,
            priority: Priority::LOW,
            title: 'Vérifié, score bas : ' . count($entries) . ' offre(s) sous le seuil de notification individuelle',
            reasons: $lines,
        );
    }

    public function sourceHealth(SourceHealth $health): Notification
    {
        return $this->shared->sourceHealth($health);
    }

    public function sourceRecovered(SourceHealth $health): Notification
    {
        return $this->shared->sourceRecovered($health);
    }

    /** @param list<SourceHealth> $health */
    public function heartbeat(int $runs, int $matches, array $health, string $sinceIso, ?string $refusal = null, int $failedPasses = 0): Notification
    {
        $n = $this->shared->heartbeat($runs, $matches, $health, $sinceIso);
        $reasons = $n->reasons;

        // FAILED PASSES FIRST. A beat reading `toutes les sources sont OK` beside passes that all died
        // is the car beat's round-5 defect, and the bad news is the reason a beat is worth reading.
        if ($failedPasses > 0) {
            array_unshift($reasons, $failedPasses . ' passe(s) EN ÉCHEC — voir les journaux');
        }

        if ($refusal !== null) {
            // Q27: what the previous start refused, now that this one reached the channel.
            $reasons[] = 'démarrage précédent refusé : ' . $refusal;
        }

        return new Notification(
            kind: $n->kind,
            priority: $n->priority,
            title: 'job-watch tourne — ' . $matches . ' correspondance(s) depuis ' . $sinceIso,
            reasons: $reasons,
        );
    }

    /** `linkedin · 72/100 — Senior Software Engineer · Aneo · Montrouge · hybride` */
    private function headline(JobListing $offer, ?int $score): string
    {
        $parts = [trim($offer->title) !== '' ? trim($offer->title) : 'offre sans intitulé'];
        foreach ([$offer->company, $offer->location] as $field) {
            if (trim($field) !== '') {
                $parts[] = trim($field);
            }
        }
        // An UNSTATED mode is left out, never printed as on site: hard rule 9 at the display layer.
        if ($offer->workMode !== null) {
            $parts[] = self::MODE_LABELS[$offer->workMode];
        }
        $headline = implode(' · ', $parts);

        return $offer->sourceName . ' · ' . ($score === null ? $headline : $score . '/100 — ' . $headline);
    }
}
