<?php

declare(strict_types=1);

namespace Scout\Car;

use Scout\Rent\Notify\Formatter;
use Scout\Core\Notify\Notification;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Priority;
use Scout\Core\SourceHealth;

/**
 * What a car push says. The SOURCE leads every title (developer ruling, 2026-08-29), then score,
 * make/model/year, mileage and price — the facts a phone shows first. Health and recovery notices
 * are the rent formatter's, unchanged: a broken source is a broken source in either domain.
 */
final readonly class VehicleFormatter
{
    public function __construct(private Formatter $shared = new Formatter()) {}

    public function match(VehicleListing $car, VehicleVerdict $verdict): Notification
    {
        return new Notification(
            kind: NotificationKind::MATCH,
            priority: $verdict->highPriority ? Priority::HIGH : Priority::NORMAL,
            title: $this->headline($car, $verdict->score),
            reasons: $verdict->reasons,
            url: $car->url,
            score: $verdict->score,
            sourceName: $car->sourceName,
        );
    }

    /**
     * The daily ROLLUP (A5, row 6): matches held back by `push_min_score`, one line each, the
     * score leading so the reader can skim. Not a digest — see `NotificationKind::ROLLUP`.
     *
     * @param list<array{car: VehicleListing, score: ?int}> $entries
     */
    public function rollup(array $entries): Notification
    {
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = '• ' . $this->headline($entry['car'], $entry['score']);
        }

        return new Notification(
            kind: NotificationKind::ROLLUP,
            priority: Priority::LOW,
            title: 'Vérifié, score bas : ' . count($entries) . ' véhicule(s) sous le seuil de notification individuelle',
            reasons: $lines,
        );
    }

    public function priceDrop(VehicleListing $car, int $previousEur, int $currentEur): Notification
    {
        $delta = $previousEur - $currentEur;
        $pct = $previousEur > 0 ? round($delta * 100 / $previousEur, 1) : 0.0;

        return new Notification(
            kind: NotificationKind::PRICE_DROP,
            priority: Priority::NORMAL,
            title: $car->sourceName . ' · Baisse de prix — ' . self::name($car) . ' · ' . self::eur($currentEur),
            reasons: [
                self::eur($previousEur) . ' → ' . self::eur($currentEur) . ' (−' . self::eur($delta) . ', −'
                    . rtrim(rtrim(number_format($pct, 1, ',', ' '), '0'), ',') . ' %)',
            ],
            url: $car->url,
            sourceName: $car->sourceName,
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

        // FAILED PASSES, and this is not decoration (round-5 panel, 2026-08-31). Round 4 moved the
        // car beat into a `finally` so a throwing pass still beats — and left this out, so the beat
        // then rendered `0 exécution(s)` beside `toutes les sources sont OK` while every pass was
        // dying. Before that fix a throwing pass emitted NOTHING, and silence past the interval is
        // itself the signal; afterwards it affirmatively said all was well. That is strictly worse,
        // and it is verbatim the state the rent beat's own comment describes as its 2026-08-24
        // defect. FIRST in the list, because the reason a beat is worth reading is the bad news.
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
            title: 'car-watch tourne — ' . $matches . ' correspondance(s) depuis ' . $sinceIso,
            reasons: $reasons,
        );
    }

    /** `paruvendu · 78/100 — Renault Austral 2023 · 26 000 km · 21 000 €` */
    private function headline(VehicleListing $car, ?int $score): string
    {
        $parts = [self::name($car)];
        if ($car->mileageKm !== null) {
            $parts[] = number_format($car->mileageKm, 0, ',', ' ') . ' km';
        }
        if ($car->priceEur !== null) {
            $parts[] = self::eur($car->priceEur);
        }
        $closing = self::closing($car);
        if ($closing !== null) {
            $parts[] = $closing;
        }
        $headline = implode(' · ', $parts);

        return $car->sourceName . ' · ' . ($score === null ? $headline : $score . '/100 — ' . $headline);
    }

    /**
     * WHEN AN AUCTION LOT STOPS BEING WORTH OPENING — auction rule 2, on the headline because the
     * push and every rollup line share it. Rendered in Europe/Paris, the sale's own zone, never the
     * host's. A sale whose opening and closing fall on one Paris day is a LIVE saleroom window
     * (`vente le 12/10 09:30–18:00`); otherwise the closing is what matters (`clôture 25/09 15:00`).
     * An instant that will not parse is shown as written: leaving it out would read as no closing.
     */
    private static function closing(VehicleListing $car): ?string
    {
        if ($car->closingAt === null) {
            return null;
        }
        $close = self::paris($car->closingAt);
        if ($close === null) {
            return 'clôture ' . $car->closingAt;
        }
        $open = $car->saleOpensAt === null ? null : self::paris($car->saleOpensAt);
        if ($open !== null && $open->format('Y-m-d') === $close->format('Y-m-d') && $open < $close) {
            return 'vente le ' . $close->format('d/m') . ' ' . $open->format('H:i') . '–' . $close->format('H:i');
        }

        return 'clôture ' . $close->format('d/m H:i');
    }

    /** Strict: `Y-m-d\TH:i:s\Z` and nothing else, checked by round-trip (a lax parse moves instants). */
    private static function paris(string $iso): ?\DateTimeImmutable
    {
        $utc = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $iso, new \DateTimeZone('UTC'));
        if ($utc === false || $utc->format('Y-m-d\TH:i:s\Z') !== $iso) {
            return null;
        }

        return $utc->setTimezone(new \DateTimeZone('Europe/Paris'));
    }

    private static function name(VehicleListing $car): string
    {
        $name = trim(($car->make === null ? '' : ucfirst($car->make) . ' ') . ($car->model === null ? '' : ucfirst($car->model)));
        if ($name === '') {
            $name = trim($car->title) !== '' ? trim($car->title) : 'véhicule';
        }

        return $car->year === null ? $name : $name . ' ' . $car->year;
    }

    private static function eur(int $v): string
    {
        return number_format($v, 0, ',', ' ') . ' €';
    }
}
