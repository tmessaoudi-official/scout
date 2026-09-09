<?php

declare(strict_types=1);

namespace Scout\Rent\Notify;

use Scout\Rent\Core\Amenities;
use Scout\Rent\Core\Department;
use Scout\Rent\Core\DigestCause;
use Scout\Rent\Core\RawListing;
use Scout\Core\SourceHealth;
use Scout\Core\SourceStatus;
use Scout\Rent\Core\Verdict;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Priority;
use Scout\Core\Notify\Notification;

/**
 * Turns pipeline results into {@see Notification}s.
 *
 * ONE PLACE, on purpose. The rendered notification is this product's only user-facing output — there
 * is no web UI by design — so if two call sites each built their own, the two would drift and the
 * developer would learn to distrust whichever looked wrong.
 *
 * The rendering rule that matters: **a notification must be readable on a phone lock screen.** That
 * is not a style preference, it is where these are actually read, and it is why the title carries
 * the decision-relevant facts (commune, rent, size) rather than the source's own headline, which is
 * usually marketing.
 */
final readonly class Formatter
{
    /**
     * @param list<string> $duplicates same-track copies absorbed into this cluster (`source:id`)
     * @param list<array{source: string, url: ?string, direct: bool, pushed: bool}> $twins the same
     *        flat on the OTHER track (2026-08-29 ruling: two findings, one push). `direct` is the
     *        twin's route; `pushed` says the twin was already announced, in which case this push is
     *        the direct route arriving after the agency copy — the one second push the ruling keeps
     */
    public function match(RawListing $listing, Verdict $verdict, array $duplicates = [], array $twins = [], bool $listingIsDirect = false): Notification
    {
        $reasons = $verdict->reasons;

        // The OTHER ROUTE, first: it changes what the reader does with the push. Both links travel,
        // because the ruling's whole point is that the better route is never hidden.
        foreach ($twins as $twin) {
            $route = $twin['direct'] ? 'voie directe, candidature au bailleur' : 'agence / portail';
            $link = $twin['url'] === null ? '' : ' : ' . $twin['url'];
            // `$listingIsDirect` is load-bearing only by documentation: a NON-direct listing whose
            // twin was already pushed never reaches this method (the pipeline marks it and moves
            // on), so the `pushed && !direct` cell is unreachable today. Kept explicit rather than
            // folded into `$twin['pushed']`, so that if a caller ever pushes an agency copy of an
            // announced flat, the line it prints does not claim to be the direct route.
            if ($twin['pushed'] && $listingIsDirect) {
                array_unshift($reasons, '⚑ voie directe — ce bien a déjà été notifié via ' . $twin['source'] . $link);
            } else {
                array_unshift($reasons, 'aussi sur ' . $twin['source'] . ' (' . $route . ')' . $link);
            }
        }

        // Context first, because it answers "where is this?" before "why did it match?". Everything
        // on it was ALREADY extracted and simply never displayed, which is why it costs no request
        // and no new parsing. Omitted entirely when nothing is known — a line reading `· ·` would
        // be worse than its absence.
        $facts = $this->factsLine($listing);
        if ($facts !== null) {
            array_unshift($reasons, $facts);
        }

        if ($duplicates !== []) {
            // Shown rather than dropped. Knowing the same flat is on two portals is actionable — it
            // is a second application route — and a silently discarded duplicate is
            // indistinguishable from a listing that was never fetched.
            $reasons[] = 'également publié sur : ' . implode(', ', $duplicates);
        }

        return new Notification(
            kind: NotificationKind::MATCH,
            priority: $verdict->highPriority ? Priority::HIGH : Priority::NORMAL,
            title: $this->headline($listing, $verdict->score),
            reasons: $reasons,
            url: $listing->url,
            score: $verdict->score,
            sourceName: $listing->sourceName,
        );
    }

    /**
     * A rent that fell, or a listing that crossed back under a hard limit.
     *
     * `$nowQualifies` is what makes the Q33 ruling visible in the output rather than only in the
     * routing: a listing that was over the ceiling and is now under it is a NEW MATCH, not a price
     * tweak, and saying so is the difference between a glance and an application.
     */
    public function rentDrop(RawListing $listing, int $previousCc, int $currentCc, bool $nowQualifies): Notification
    {
        $delta = $previousCc - $currentCc;
        $pct = $previousCc > 0 ? round($delta * 100 / $previousCc, 1) : 0.0;

        return new Notification(
            kind: NotificationKind::PRICE_DROP,
            priority: $nowQualifies ? Priority::HIGH : Priority::NORMAL,
            title: $listing->sourceName . ' · ' . ($nowQualifies ? 'PASSE SOUS LE PLAFOND' : 'Baisse de loyer')
                . ' — ' . ($listing->commune ?? 'commune inconnue')
                . ' · ' . $currentCc . ' € CC',
            reasons: array_values(array_filter([
                $previousCc . ' € → ' . $currentCc . ' € CC (−' . $delta . ' €, −'
                    . rtrim(rtrim(number_format($pct, 1, ',', ' '), '0'), ',') . ' %)',
                $nowQualifies
                    ? 'ce bien était écarté sur le loyer ; il est désormais dans le budget'
                    : null,
                $this->sizeLine($listing),
            ])),
            url: $listing->url,
            sourceName: $listing->sourceName,
        );
    }

    /**
     * A source is not healthy.
     *
     * Covers EVERY alerting status, not `BROKEN` alone (ruled 2026-08-07, Q29). `NEVER_PRODUCED` was
     * added precisely because it hid behind `OK`, and `STALE` is what catches the schedule itself
     * having stopped — deriving those and never sending them would waste both.
     */
    public function sourceHealth(SourceHealth $health): Notification
    {
        return new Notification(
            kind: NotificationKind::SOURCE_HEALTH,
            // Not LOW. A broken source is indistinguishable from a quiet market, so the alert is the
            // only thing standing between the developer and weeks of believing nothing is available.
            //
            // FEED_SILENT stays LOW, and that is a DECISION rather than a fall-through (recorded
            // 2026-08-29, after a review round pointed out it had entered the else-branch with
            // nobody choosing). The argument for NORMAL is the comment directly above — a silent
            // feed is the purest case of "indistinguishable from a quiet market". The argument that
            // wins is consistency with its documented twin: `SourceStatus` calls STALE
            // "FEED_SILENT's twin from the other end", STALE is LOW, and NEVER_PRODUCED — also a
            // silent absence — is LOW too. Splitting the twins would say the portal stopping matters
            // more than the watcher stopping, which is not true. Revisit together, not separately.
            priority: $health->status === SourceStatus::BROKEN ? Priority::NORMAL : Priority::LOW,
            title: 'Source ' . $health->sourceName . ' : ' . $health->status->value,
            reasons: array_values(array_filter([
                $health->detail,
                $health->lastSuccessAt === null
                    ? 'aucune exécution réussie enregistrée'
                    : 'dernier succès : ' . $health->lastSuccessAt,
            ])),
            sourceName: $health->sourceName,
        );
    }

    public function sourceRecovered(SourceHealth $health): Notification
    {
        return new Notification(
            kind: NotificationKind::SOURCE_RECOVERED,
            priority: Priority::LOW,
            title: 'Source ' . $health->sourceName . ' : rétablie',
            reasons: ['dernier relevé : ' . $health->lastCount . ' annonce(s)'],
            sourceName: $health->sourceName,
        );
    }

    /**
     * The "à vérifier" rollup.
     *
     * One notification for the whole batch rather than one per listing — that is what makes it a
     * digest rather than a second notification stream, and it is the reason the fail-closed rule can
     * afford to send doubtful listings here at all.
     *
     * @param list<array{listing: RawListing, verdict: Verdict}> $entries
     */
    /**
     * @param list<array{listing: RawListing, verdict: Verdict, key: string, keys: list<string>}> $entries  the tenure-doubt bin
     * @param list<array{listing: RawListing, verdict: Verdict, key: string, keys: list<string>}> $lowScore the A5 queue —
     *                                                                                                     settled matches held back by `push_min_score`
     */
    public function digest(array $entries, array $lowScore = []): Notification
    {
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = $this->digestLine($entry, null);
        }

        // ONE MAIL, TWO QUEUES, SEPARATED (A5, row 6). The rollup sits UNDER the §1 bin with its
        // own heading, and its entries never touch the title or the regime clause below: those
        // are earned by the tenure-doubt entries alone, and a low-score entry is a settled match.
        if ($lowScore !== []) {
            $lines[] = '— vérifié, score bas : ' . count($lowScore) . ' annonce(s) sous le seuil de notification individuelle —';
            foreach ($lowScore as $entry) {
                $score = $entry['verdict']->score;
                $lines[] = $this->digestLine($entry, ($score ?? 0) > 0 ? $score : null);
            }
        }
        if ($entries === []) {
            // ROLLUP, not DIGEST (C2 round 6, correctness P2): the rent digest MEANS tenure doubt,
            // and a mail holding only settled matches is not one — the same reason the car side
            // carries its own kind. A channel filing DIGEST as « à vérifier » cannot mistake it.
            return new Notification(
                kind: NotificationKind::ROLLUP,
                priority: Priority::LOW,
                title: 'Vérifié, score bas : ' . count($lowScore) . ' annonce(s)',
                reasons: $lines,
            );
        }

        // The regime clause is EARNED, not assumed. The bin has had two entrances since Track 1f —
        // an undetermined tenure, and a rent that does not describe the surface it is quoted for —
        // and a listing taking the second one is typically `LLI` at full confidence. A title
        // speaking for every entry would then assert as undetermined a regime the classifier
        // settled. One entry that did not earn the clause removes it for the batch: the entry
        // bodies below still carry every reason, so the batch says less rather than something untrue.
        $everyEntryIsATenureDoubt = $entries !== [] && array_reduce(
            $entries,
            static fn (bool $carry, array $entry): bool
                => $carry && $entry['verdict']->digestCause === DigestCause::TENURE_UNDETERMINED,
            true,
        );

        return new Notification(
            kind: NotificationKind::DIGEST,
            priority: Priority::LOW,
            title: 'À vérifier : ' . count($entries) . ' annonce(s)'
                . ($everyEntryIsATenureDoubt ? ' au régime indéterminé' : ''),
            reasons: $lines,
        );
    }

    /**
     * Proof of life.
     *
     * @param list<SourceHealth> $health
     */
    public function heartbeat(int $runs, int $matches, array $health, string $sinceIso): Notification
    {
        $notOk = array_values(array_filter($health, static fn (SourceHealth $h): bool => $h->status->isAlerting()));

        return new Notification(
            kind: NotificationKind::HEARTBEAT,
            priority: Priority::LOW,
            title: 'rent-watch tourne — ' . $matches . ' correspondance(s) depuis ' . $sinceIso,
            reasons: array_values(array_filter([
                $runs . ' exécution(s), ' . count($health) . ' source(s) actives',
                $notOk === []
                    ? 'toutes les sources sont OK'
                    : count($notOk) . ' source(s) en alerte : ' . implode(', ', array_map(
                        static fn (SourceHealth $h): string => $h->sourceName . ' (' . $h->status->value . ')',
                        $notOk,
                    )),
            ])),
        );
    }

    /**
     * The line a phone shows before anything is expanded.
     *
     * Commune, size and rent, in that order, because those are what decide whether the developer
     * opens it. The source's own title is not used WHILE ANYTHING ELSE LOCATES THE LISTING — it is
     * written to sell, so it leads with adjectives and buries the facts — but it IS used when
     * neither commune nor postcode is known, in preference to the `commune inconnue` placeholder.
     * A label for the absence of information must not outrank a stated fact.
     */
    private function headline(RawListing $listing, ?int $score): string
    {
        // Commune AND postcode, since 2026-08-22. The commune alone was ambiguous — three of the
        // eight Île-de-France departements have a Neuilly — and on a source that ships no title
        // (In'li does not) the headline was the only text in the whole notification, so a bare
        // commune left the reader with a price and a link.
        $where = $listing->commune ?? '';
        $postcode = trim((string) $listing->postcode);
        if ($postcode !== '') {
            $where = $where === '' ? $postcode : $where . ' ' . $postcode;
        }

        // A PLACEHOLDER MUST NOT OUTRANK A STATED FACT. When nothing locates the listing, the title
        // is used before `commune inconnue` — which is a label for the absence of information, and
        // printing it while a real title sits unused throws away the only human-readable thing the
        // notification had. That is not hypothetical: it is the shape `scout digest` announces a
        // snapshot-less row in — a listing whose payload could not be encoded, NOT a pre-v7 row,
        // which that query cannot return at all — whose `listings` row holds a title and no
        // commune. Those entries announced themselves as `commune inconnue · 1005 € CC`, and an
        // entry nobody can act on is the display twin of the miss the command was built to fix.
        // (The pre-v7 framing stood here through two review rounds after being corrected at the
        // query itself; a premise corrected in one place and left in another is the one a reader
        // lands on from the other direction.)
        $where = $where === '' ? trim($listing->title) : $where;
        $parts = [$where === '' ? 'commune inconnue' : $where];

        $size = $this->sizeLine($listing);
        if ($size !== null) {
            $parts[] = $size;
        }

        $rent = $listing->effectiveRentCc();
        if ($rent !== null) {
            $parts[] = $rent . ' € CC';
        } elseif ($listing->rentHc !== null) {
            // Flagged, never silently shown as if it were comparable to the budget.
            $parts[] = $listing->rentHc . ' € HC';
        }

        $headline = implode(' · ', $parts);

        // THE SOURCE LEADS. Developer ruling, 2026-08-29: "I want the source visible in the
        // title/subject so I know what's more priority, by the source." On a phone the title is
        // what is read first, and which portal a flat came from is what decides how fast to act.
        return $listing->sourceName . ' · ' . ($score === null ? $headline : $score . '/100 — ' . $headline);
    }

    /**
     * ONE entry line, for BOTH digest lists.
     *
     * The two lists rendered this with two byte-identical loops until 2026-09-09, differing only in
     * the score argument. *A fix landing on one of two symmetric surfaces* is this repo's named
     * recurring defect — it was committed five times during one §1 review, three of them inside the
     * fix for the one before — and a display line is where it is cheapest to prevent and hardest to
     * notice, because both halves keep rendering something plausible.
     *
     * Position is `• headline — context — reason`: the context answers *what is this flat* and the
     * reason answers *why is it here*, which is the order the push already uses.
     *
     * SILENCE CARRIES NOTHING EXTRA. Most stored rows state no floor and five of the eight sources
     * carry no listing prose at all, so on those the line must come back byte-identical to what it
     * was before this feature — a dangling separator would announce an absence of information as
     * though it were information, which is hard rule 9 read backwards at the display layer.
     *
     * @param array{listing: RawListing, verdict: Verdict} $entry
     */
    private function digestLine(array $entry, ?int $score): string
    {
        $context = $this->contextBits($entry['listing']);

        return '• ' . $this->headline($entry['listing'], $score)
            . ($context === [] ? '' : ' — ' . implode(' · ', $context))
            . ($entry['verdict']->reasons === [] ? '' : ' — ' . $entry['verdict']->reasons[0]);
    }

    /**
     * Departement, floor, lift and amenities — the INDIVIDUAL push's context line.
     *
     * The departement is this method's only content of its own; everything else comes from
     * {@see contextBits()}, which the digest shares. It is here and not there because a push
     * carries no other context, while a digest entry sits directly under a headline that already
     * prints the postcode — see that method for the measurement that decided it.
     */
    private function factsLine(RawListing $listing): ?string
    {
        $bits = [];

        $department = Department::label($listing->postcode);
        if ($department !== null) {
            $bits[] = $department;
        }

        foreach ($this->contextBits($listing) as $bit) {
            $bits[] = $bit;
        }

        return $bits === [] ? null : implode(' · ', $bits);
    }

    /**
     * Floor, lift and amenities — what IS this flat, shared by the push and by both digest bins.
     *
     * Hard rule 9 governs every entry. `floor === 0` is RDC and REAL, not absent: a ground-floor
     * flat that silently loses its floor is the display-layer twin of one rejected for not stating
     * it. And an UNMENTIONED lift is not an absent one — `null` says nothing, `false` says there is
     * none — because "sans ascenseur" invented about a building nobody described is exactly the
     * kind of fabrication that makes someone skip a flat that has one.
     *
     * @return list<string>
     */
    private function contextBits(RawListing $listing): array
    {
        $bits = [];

        if ($listing->floor !== null) {
            $bits[] = match (true) {
                $listing->floor <= 0 => 'RDC',
                $listing->floor === 1 => '1er étage',
                default => $listing->floor . 'e étage',
            };
        }

        if ($listing->hasElevator !== null) {
            $bits[] = $listing->hasElevator ? 'avec ascenseur' : 'sans ascenseur';
        }

        // TERRACE, CAVE, PARKING — Track 7-B, developer ruling 2026-09-08. It joins this line rather
        // than getting one of its own because it answers the same question the rest of the line
        // does: what IS this flat. It rejects nothing and scores nothing (hard rule 8).
        //
        // Every entry obeys the same rule as the three above: an amenity nobody mentioned prints
        // nothing, and printing nothing means the ad was silent — never that the flat lacks it. Five
        // of the eight sources carry no listing prose, so on those this half of the line is always
        // empty and that is the correct output rather than a gap.
        //
        // REACH, stated because it decides how often this is seen at all. Until 2026-09-09 the
        // whole line was called from `match()` ALONE, so at `push_min_score: 55` about one match in
        // ten showed it and the two bins carrying most of the volume showed a headline and a reason
        // and nothing else. It is now shared with both digest lists — hence this method existing at
        // all, rather than the digest growing a second copy of the same rendering.
        //
        // THE DEPARTEMENT IS THE HALF THAT STAYED BEHIND, and that is measured rather than styled:
        // the full line fires on 100 % of digest rows, but on 61–74 % of them the departement is
        // the ONLY thing it adds — restating the postcode `headline()` already prints two fields to
        // its left (rollup 40/62, tenure bin 58/94, all 1 282 stored matches 958/1282). Without it
        // the digest line fires on 35–38 % of the two live bins and every character is new.
        foreach (Amenities::read($listing->description) as $amenity) {
            $bits[] = $amenity;
        }

        return $bits;
    }

    private function sizeLine(RawListing $listing): ?string
    {
        $bits = [];
        if ($listing->rooms !== null) {
            $bits[] = 'T' . $listing->rooms;
        }
        if ($listing->surfaceM2 !== null) {
            $bits[] = rtrim(rtrim(number_format($listing->surfaceM2, 1, ',', ' '), '0'), ',') . ' m²';
        }

        return $bits === [] ? null : implode(' ', $bits);
    }
}
