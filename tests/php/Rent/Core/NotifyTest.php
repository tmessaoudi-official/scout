<?php

declare(strict_types=1);

namespace Scout\Tests\Rent\Core;

use PHPUnit\Framework\TestCase;
use Scout\Core\Notify\Channel;
use Scout\Core\Notify\ChannelError;
use Scout\Core\Notify\ConsoleChannel;
use Scout\Core\Notify\EmailChannel;
use Scout\Core\Notify\FileTransport;
use Scout\Rent\Notify\Formatter;
use Scout\Core\Notify\Notification;
use Scout\Core\Notify\NotificationKind;
use Scout\Core\Notify\Notifier;
use Scout\Core\Notify\NtfyChannel;
use Scout\Core\Notify\Priority;
use Scout\Core\Notify\SendmailTransport;
use Scout\Core\Notify\SmtpTransport;
use Scout\Rent\Core\DigestCause;
use Scout\Rent\Core\RawListing;
use Scout\Core\Redact;
use Scout\Core\SourceHealth;
use Scout\Core\SourceStatus;
use Scout\Rent\Core\Verdict;

/**
 * The notification layer — the product's only user-facing output.
 *
 * Categories: **payload** (what a phone lock screen actually shows) · **routing** (priority, and the
 * Q31 confidence gate) · **refusal scope** (Q28) · **delivery** (a failure is never silent) ·
 * **secrets** (the ntfy topic, which no name-based masker can reach).
 */
final class NotifyTest extends TestCase
{
    private function listing(array $o = []): RawListing
    {
        // `array_key_exists`, not `??`. The null coalescer swallows an EXPLICIT null override, so
        // `['rentCc' => null]` silently kept the 1450 default and the hors-charges test asserted
        // against a listing that had a CC rent after all. A helper that quietly ignores what a test
        // asked for makes the test prove something other than what it says.
        $pick = static fn (string $key, mixed $default): mixed
            => array_key_exists($key, $o) ? $o[$key] : $default;

        return new RawListing(
            sourceName: $pick('source', 'inli'),
            externalId: $pick('id', 'x-1'),
            title: $pick('title', 'SUPERBE APPARTEMENT RARE SUR LE MARCHE'),
            url: $pick('url', 'https://example.test/a/1'),
            commune: $pick('commune', 'Sartrouville'),
            postcode: $pick('postcode', '78500'),
            rentCc: $pick('rentCc', 1450),
            rentHc: $pick('rentHc', null),
            surfaceM2: $pick('surface', 88.0),
            rooms: $pick('rooms', 4),
            floor: $pick('floor', null),
            hasElevator: $pick('hasElevator', null),
            description: $pick('description', ''),
        );
    }

    // ------------------------------------------------- Track 7-B, the amenity line

    /**
     * TERRACE, CAVE, PARKING JOIN THE CONTEXT LINE (developer ruling, 2026-09-08).
     *
     * They answer the same question the departement, the floor and the lift do — what IS this flat
     * — so they join that line rather than getting one of their own, and they obey the same rule:
     * an amenity nobody mentioned prints nothing.
     */
    public function testTheAmenitiesTheAdStatesJoinTheContextLine(): void
    {
        $n = (new Formatter())->match(
            $this->listing([
                'floor' => 2,
                'hasElevator' => true,
                'description' => 'Beau T4 avec terrasse et un parking inclus dans le loyer.',
            ]),
            Verdict::matched(82, ['mention explicite « LLI »'], true),
        );

        self::assertContains('Yvelines (78) · 2e étage · avec ascenseur · terrasse · parking inclus', $n->reasons);
    }

    /**
     * THE COUNTERWEIGHT, and without it the feature is satisfied by printing every amenity always.
     *
     * Five of the eight rent sources carry no listing prose at all — PAP's `description` is a fixed
     * banner on all 57 stored rows — so on those the line must come back exactly as it did before
     * this feature existed. An absent amenity means the ad was SILENT, never that the flat lacks
     * one: hard rule 9 at the display layer, the same rule that makes an unmentioned lift `null`
     * rather than `false`.
     */
    public function testAnAdThatMentionsNoAmenityAddsNothingToTheLine(): void
    {
        $n = (new Formatter())->match(
            $this->listing([
                'floor' => 2,
                'hasElevator' => true,
                'description' => 'PAP.fr De Particulier à Particulier',
            ]),
            Verdict::matched(82, [], true),
        );

        self::assertContains('Yvelines (78) · 2e étage · avec ascenseur', $n->reasons, 'byte-identical to the pre-Track-7 line');
    }


    // ------------------------------------------- Track 7-B, phase 2: the digest and the rollup

    /**
     * THE CONTEXT LINE REACHES THE DIGEST (developer ruling, 2026-09-09).
     *
     * `factsLine()` had ONE call site — `match()` — so at `push_min_score: 55` it travelled on
     * about one match in ten and the two bins that carry most of the volume showed a headline and a
     * reason and nothing else. The entry line now carries the floor, the lift and the amenities
     * between them.
     */
    public function testADigestEntryCarriesTheFloorTheLiftAndTheAmenities(): void
    {
        $n = (new Formatter())->digest([
            [
                'listing' => $this->listing([
                    'floor' => 2,
                    'hasElevator' => true,
                    'description' => 'Beau T4 avec terrasse et un parking inclus dans le loyer.',
                ]),
                'verdict' => Verdict::digest(['régime indéterminé'], DigestCause::TENURE_UNDETERMINED),
            ],
        ]);

        self::assertStringContainsString(
            '2e étage · avec ascenseur · terrasse · parking inclus — régime indéterminé',
            $n->reasons[0],
        );
    }

    /**
     * THE ROLLUP HALF, which is a SEPARATE loop over a separate queue.
     *
     * `digest()` rendered its two entry lists with two byte-identical loops, and *a fix landing on
     * one of two symmetric surfaces* is this repo's named recurring defect — it was committed five
     * times in one §1 review, three of them inside the fix for the one before. This case is what
     * makes the shared renderer provable rather than merely intended: it fails if the widening
     * lands on the tenure bin alone.
     */
    public function testARollupEntryCarriesTheSameContextAsADigestEntry(): void
    {
        $n = (new Formatter())->digest([], [
            [
                'listing' => $this->listing([
                    'floor' => 0,
                    'description' => 'Charmant rez-de-chaussée avec jardin privatif et une cave.',
                ]),
                'verdict' => Verdict::matched(48, ['sous le seuil de notification individuelle'], false),
            ],
        ]);

        self::assertStringContainsString('RDC · jardin · cave', implode("\n", $n->reasons));
    }

    /**
     * SILENCE CARRIES NOTHING EXTRA — hard rule 9 at the display layer, and the half a display
     * feature always risks losing.
     *
     * Five of the eight rent sources carry no listing prose at all and most stored rows state no
     * floor, so on those the entry line must come back byte-identical to what it was before this
     * change. A dangling separator would be the feature announcing an absence of information as
     * though it were information.
     */
    public function testADigestEntryWithNothingToAddIsByteIdenticalToTheOldLine(): void
    {
        $listing = $this->listing(['description' => 'PAP.fr De Particulier à Particulier']);

        $n = (new Formatter())->digest([
            ['listing' => $listing, 'verdict' => Verdict::digest(['régime indéterminé'], DigestCause::TENURE_UNDETERMINED)],
        ]);

        // The literal is the pre-change output pasted verbatim, which is what "byte-identical"
        // can mean here: composing it from `match()->title` asserts something else, because the
        // individual push carries a score and the digest headline passes `null`.
        self::assertSame(
            '• inli · Sartrouville 78500 · T4 88 m² · 1450 € CC — régime indéterminé',
            $n->reasons[0],
        );
    }

    /**
     * THE DEPARTEMENT IS THE HALF THAT DOES **NOT** TRAVEL, and this is the counterweight that
     * stops someone tidying the two surfaces back into one.
     *
     * Measured over the live store before the ruling: the full line fires on 100 % of digest rows,
     * but on 61–74 % of them the departement is the ONLY thing it adds — a restatement of the
     * postcode the headline already prints two fields to its left (rollup 40/62, tenure bin 58/94,
     * all 1 282 stored matches 958/1282). Dropped from the digest, the line fires on 35–38 % of the
     * two live bins and every character of it is new.
     *
     * The individual push keeps it: there the line is the only context there is, and this asserts
     * BOTH directions in one case, because either half alone is satisfied by deleting a feature.
     */
    public function testTheDepartementStaysOnTheIndividualPushAndOffTheDigestLine(): void
    {
        $listing = $this->listing(['floor' => 2, 'hasElevator' => true]);

        $digest = (new Formatter())->digest([
            ['listing' => $listing, 'verdict' => Verdict::digest(['régime indéterminé'], DigestCause::TENURE_UNDETERMINED)],
        ]);
        $push = (new Formatter())->match($listing, Verdict::matched(82, ['mention explicite « LLI »'], true));

        self::assertStringNotContainsString('Yvelines', $digest->reasons[0], 'the headline already prints 78500');
        self::assertStringContainsString('2e étage · avec ascenseur', $digest->reasons[0]);
        self::assertContains('Yvelines (78) · 2e étage · avec ascenseur', $push->reasons);
    }

    // ---------------------------------------------------------------- payload

    public function testTheHeadlineLeadsWithFactsNotTheSourcesMarketing(): void
    {
        // A notification is read on a lock screen. The source's own title is written to sell, so it
        // leads with adjectives and buries the commune, the size and the rent — which are the three
        // things that decide whether the developer opens it.
        $n = (new Formatter())->match(
            $this->listing(),
            Verdict::matched(82, ['mention explicite « LLI »'], true),
        );

        // The postcode joined the commune on 2026-08-22 — see
        // testTheHeadlineNamesThePostcodeAsWellAsTheCommune, which owns that decision. What this
        // test is about is unchanged and is the reason it keeps its own name: the source's TITLE is
        // still absent, and still must be.
        self::assertSame('inli · 82/100 — Sartrouville 78500 · T4 88 m² · 1450 € CC', $n->title);
        self::assertStringNotContainsString('SUPERBE', $n->title);
    }

    public function testTheHeadlineNamesThePostcodeAsWellAsTheCommune(): void
    {
        // Added 2026-08-22, on a real complaint: In'li ships no title and the headline carried the
        // commune alone, so a notification read as a bare price and a link. A commune name without
        // its postcode is genuinely ambiguous in Île-de-France — there is a Neuilly in three
        // departements — and the postcode is the one fact every source already provides.
        $n = (new Formatter())->match(
            $this->listing(),
            Verdict::matched(82, ['mention explicite « LLI »'], true),
        );

        self::assertSame('inli · 82/100 — Sartrouville 78500 · T4 88 m² · 1450 € CC', $n->title);
    }

    public function testTheFactsLineNamesTheDepartmentAndWhatIsKnownAboutTheBuilding(): void
    {
        // Everything on this line was ALREADY extracted and simply never shown. It costs no request
        // and no new parsing — which is why it ships before the detail-page hydration that floor and
        // lift need on the sources that do not put them on the card.
        $n = (new Formatter())->match(
            $this->listing(['floor' => 2, 'hasElevator' => true]),
            Verdict::matched(82, ['mention explicite « LLI »'], true),
        );

        self::assertContains('Yvelines (78) · 2e étage · avec ascenseur', $n->reasons);
    }

    public function testTheGroundFloorIsSaidRatherThanTreatedAsAbsent(): void
    {
        // Hard rule 9 at the display layer: `floor === 0` is falsy and REAL. A RDC flat that
        // silently loses its floor is the same defect as one rejected for not stating it.
        $n = (new Formatter())->match(
            $this->listing(['floor' => 0]),
            Verdict::matched(50, [], true),
        );

        self::assertContains('Yvelines (78) · RDC', $n->reasons);
    }

    public function testAnUNMENTIONEDLiftIsNotReportedAsAbsentOne(): void
    {
        // `null` is not `false`. "sans ascenseur" about a building nobody described is a fact the
        // notification invented, and it is the kind that makes someone skip a flat that has one.
        $withoutInfo = (new Formatter())->match($this->listing(), Verdict::matched(50, [], true));
        $knownAbsent = (new Formatter())->match(
            $this->listing(['hasElevator' => false]),
            Verdict::matched(50, [], true),
        );

        self::assertContains('Yvelines (78)', $withoutInfo->reasons);
        self::assertContains('Yvelines (78) · sans ascenseur', $knownAbsent->reasons);
    }

    public function testAListingOutsideTheKnownDepartmentsStillFormats(): void
    {
        // Logirep publishes nationally. Nothing outside Île-de-France can match today, but a
        // formatter that throws on an unexpected postcode would take the whole pass down for a
        // listing it was only ever going to reject.
        $n = (new Formatter())->match(
            $this->listing(['postcode' => '33000', 'commune' => 'Bordeaux']),
            Verdict::matched(50, [], true),
        );

        self::assertSame('inli · 50/100 — Bordeaux 33000 · T4 88 m² · 1450 € CC', $n->title);
        foreach ($n->reasons as $reason) {
            self::assertStringNotContainsString('(33)', $reason, 'an unknown departement is omitted, never guessed');
        }
    }

    public function testAnUnlocatedListingIsNamedByItsTitleRatherThanByThePlaceholder(): void
    {
        // `commune inconnue` is a label for the ABSENCE of information, and printing it while a real
        // title sits unused throws away the only human-readable fact the notification had. It is the
        // shape `scout digest` announces a snapshot-less row in — its `listings` row holds a title
        // and no commune — so before this rule those entries read `commune inconnue · 1005 € CC`,
        // which nobody can act on. In'li is the live case: it ships no title, which is why the
        // placeholder still has to survive when there genuinely is nothing (asserted below).
        $n = (new Formatter())->match(
            $this->listing(['commune' => null, 'postcode' => null, 'title' => 'T3 à Longjumeau']),
            Verdict::matched(50, [], true),
        );

        self::assertStringContainsString('T3 à Longjumeau', $n->title);
        self::assertStringNotContainsString('commune inconnue', $n->title);
    }

    public function testThePlaceholderIsStillUsedWhenThereIsNoTitleEither(): void
    {
        // The counterweight. In'li ships no title at all, so the placeholder must survive as the
        // honest answer when there genuinely is nothing — a blank leading segment would read as a
        // formatting bug rather than as missing data.
        $n = (new Formatter())->match(
            $this->listing(['commune' => null, 'postcode' => null, 'title' => '   ']),
            Verdict::matched(50, [], true),
        );

        self::assertStringContainsString('commune inconnue', $n->title);
    }

    public function testAnHorsChargesRentIsFLAGGEDRatherThanShownAsIfComparable(): void
    {
        // A 1750 € HC flat is roughly 1900 € CC. Showing it as "1750 €" next to an 1800 € budget
        // invites exactly the wrong conclusion.
        $n = (new Formatter())->match(
            $this->listing(['rentCc' => null, 'rentHc' => 1750]),
            Verdict::matched(60, [], false),
        );

        self::assertStringContainsString('1750 € HC', $n->title);
        self::assertStringNotContainsString('CC', $n->title);
    }

    public function testAKnownDuplicateIsShownRatherThanDropped(): void
    {
        // The same flat on two portals is a second application route. A silently discarded duplicate
        // is indistinguishable from a listing that was never fetched.
        $n = (new Formatter())->match(
            $this->listing(),
            Verdict::matched(70, ['mention explicite « LLI »'], false),
            ['leboncoin:zz9'],
        );

        self::assertContains('également publié sur : leboncoin:zz9', $n->reasons);
    }

    public function testEveryMatchCarriesItsScoreAndItsReasons(): void
    {
        // spec/PROJECT_BRIEF.md §5. A notification saying "LLI, 0.9" is not actionable.
        $n = (new Formatter())->match(
            $this->listing(),
            Verdict::matched(82, ['champ structuré financement = LLI'], true),
        );

        self::assertSame(82, $n->score);
        self::assertContains('champ structuré financement = LLI', $n->reasons);
    }

    // ---------------------------------------------------------------- routing

    public function testAHighScoringConfidentMatchIsHighPriority(): void
    {
        $n = (new Formatter())->match($this->listing(), Verdict::matched(82, [], true));
        self::assertSame(Priority::HIGH, $n->priority);
    }

    public function testAMatchThatIsNotHighPriorityIsStillDeliveredImmediately(): void
    {
        // Nothing is batched (ruled 1c). The premise of the tool is that good stock goes within
        // hours, so a batching window would defeat it for the listings it exists to catch.
        $n = (new Formatter())->match($this->listing(), Verdict::matched(40, [], false));
        self::assertSame(Priority::NORMAL, $n->priority);
    }

    public function testARentDropCrossingTheCeilingIsAnnouncedAsANewMatch(): void
    {
        // Q33. A listing seen at 1810 € was disqualified, so nothing was sent. At 1795 € it is a
        // full match — and the reduction is only 15 €, which sits below BOTH rent-change
        // thresholds. Treating it as an ordinary price tweak loses the flat.
        $n = (new Formatter())->rentDrop($this->listing(), 1810, 1795, true);
        self::assertStringStartsWith('inli · ', $n->title, 'the source leads every listing title, drops included (2026-08-29)');

        self::assertSame(Priority::HIGH, $n->priority);
        self::assertStringContainsString('PASSE SOUS LE PLAFOND', $n->title);
        self::assertContains('ce bien était écarté sur le loyer ; il est désormais dans le budget', $n->reasons);
    }

    public function testAnOrdinaryRentDropIsNormalPriority(): void
    {
        $n = (new Formatter())->rentDrop($this->listing(), 1500, 1400, false);

        self::assertSame(Priority::NORMAL, $n->priority);
        self::assertSame(NotificationKind::PRICE_DROP, $n->kind);
    }

    public function testEveryAlertingSourceStatusCanBeFormatted(): void
    {
        // Q29: the 1c table routed SOURCE_BROKEN alone, while SEVEN statuses now alert. NEVER_PRODUCED
        // was added precisely because it hid behind OK; deriving it and never sending it wastes it.
        // FEED_SILENT joined them 2026-08-29 — a source that keeps reporting while its feed stopped.
        $formatted = 0;

        foreach (SourceStatus::cases() as $status) {
            if (!$status->isAlerting()) {
                continue;
            }

            $n = (new Formatter())->sourceHealth(new SourceHealth(
                sourceName: 'inli',
                status: $status,
                detail: 'détail de test',
            ));

            self::assertSame(NotificationKind::SOURCE_HEALTH, $n->kind);
            self::assertStringContainsString($status->value, $n->title);
            ++$formatted;
        }

        // The floor tracks the real count. Left at 5 while seven statuses alerted, TWO members could
        // have been deleted with this guard still green — a floor that lags is a floor that stops
        // guarding, which is what it was found doing.
        self::assertGreaterThanOrEqual(7, $formatted, 'the alerting set shrank — check SourceStatus::isAlerting()');
    }

    public function testTheHeartbeatIsSentEvenWhenNothingMatched(): void
    {
        // Q27, and the whole reason it exists: a dead watcher and a quiet rental market both emit
        // nothing, so silence is only a signal if something breaks it on a schedule.
        $n = (new Formatter())->heartbeat(96, 0, [
            new SourceHealth(sourceName: 'inli', status: SourceStatus::OK),
        ], '2026-08-06T18:00:00Z');

        self::assertSame(NotificationKind::HEARTBEAT, $n->kind);
        self::assertStringContainsString('0 correspondance', $n->title);
        self::assertContains('toutes les sources sont OK', $n->reasons);
    }

    public function testTheHeartbeatNamesSourcesThatAreNotOk(): void
    {
        $n = (new Formatter())->heartbeat(96, 3, [
            new SourceHealth(sourceName: 'inli', status: SourceStatus::OK),
            new SourceHealth(sourceName: 'seqens', status: SourceStatus::BROKEN),
        ], '2026-08-06T18:00:00Z');

        self::assertStringContainsString('seqens (' . SourceStatus::BROKEN->value . ')', implode(' ', $n->reasons));
    }

    public function testTheDigestIsOneRollupRatherThanOneNotificationPerListing(): void
    {
        // What makes it a digest rather than a second notification stream — and the reason the
        // fail-closed rule can afford to send doubtful listings there at all.
        $n = (new Formatter())->digest([
            ['listing' => $this->listing(['id' => 'a']), 'verdict' => Verdict::digest(['régime indéterminé'], DigestCause::TENURE_UNDETERMINED)],
            ['listing' => $this->listing(['id' => 'b']), 'verdict' => Verdict::digest(['régime indéterminé'], DigestCause::TENURE_UNDETERMINED)],
        ]);

        self::assertSame(NotificationKind::DIGEST, $n->kind);
        self::assertSame(Priority::LOW, $n->priority);
        self::assertStringContainsString('2 annonce(s)', $n->title);
        self::assertCount(2, $n->reasons);
    }

    // ---------------------------------------------------------------- refusal scope (Q28)

    public function testNoUsableChannelIsAFatalRefusal(): void
    {
        $notifier = new Notifier([$this->brokenChannel('ntfy', 'RENT_NTFY_TOPIC is not set')]);

        $problem = $notifier->fatalProblem();
        self::assertNotNull($problem);
        self::assertStringContainsString('found and never delivered', (string) $problem);
        self::assertStringContainsString('RENT_NTFY_TOPIC is not set', (string) $problem);
    }

    public function testAnEmptyChannelListIsAlsoFatal(): void
    {
        self::assertNotNull((new Notifier([]))->fatalProblem());
    }

    public function testOneBrokenChannelDoesNotStopTheProcessWhenAnotherWorks(): void
    {
        // The Q28 correction. An expired SMTP password must not take down the sources, the seen-set
        // and the price history to punish one channel.
        // The survivor has to be a REMOTE channel. It used to be `console`, which made this test
        // pass for a reason unrelated to what it names — see testConsoleAloneCanStartButCanNeverDeliver.
        $notifier = new Notifier([
            $this->brokenChannel('email', 'SMTP_TO is not set'),
            $this->workingChannel('ntfy'),
        ]);

        self::assertNull($notifier->fatalProblem());
        self::assertSame(['email' => 'SMTP_TO is not set'], $notifier->disabledReport());
    }

    public function testConsoleAloneIsNotARemoteChannel(): void
    {
        // Under Q8's Docker deployment, `console` is the container log — which is not a
        // notification channel for anyone.
        $notifier = new Notifier([new ConsoleChannel($this->tempStream())]);

        self::assertFalse($notifier->hasRemoteChannel());
    }

    // ------------------------------------------- console does not COUNT (round 7 P0)

    /**
     * The ruling both `Notifier` and `ConsoleChannel` already stated in prose, now enforced.
     *
     * Console-only still STARTS — `scout run --once` at a terminal is exactly that, and refusing
     * would take a working local run away to punish a deployment mistake. What it cannot do is
     * deliver, so nothing is ever marked notified and every announcement is reported undelivered.
     */
    public function testConsoleAloneCanStartButCanNeverDeliver(): void
    {
        $notifier = new Notifier([new ConsoleChannel($this->tempStream())]);

        self::assertNull($notifier->fatalProblem(), 'a local run is still allowed');
        self::assertFalse($notifier->delivered([]), 'but a container log is not a delivery');
        self::assertFalse($notifier->hasRemoteChannel());
    }

    /**
     * The P0 itself: one console print satisfied every "did it reach the user" gate in the tree.
     *
     * `delivered()` is what `markNotified()`, the 24 h alert cooldown, the heartbeat marker and
     * `test-notify`'s exit code all ask. With console counting, a transient ntfy outage announced
     * the flat to a log, wrote `notified_as = 'MATCH'`, and suppressed it for ever once the
     * network came back.
     */
    public function testAConsolePrintDoesNotRescueAFailedRemoteSend(): void
    {
        $stream = $this->tempStream();
        $notifier = new Notifier([$this->failingChannel('ntfy'), new ConsoleChannel($stream)]);

        $failures = $notifier->send($this->anyNotification());

        self::assertCount(1, $failures);
        self::assertFalse(
            $notifier->delivered($failures),
            'a container log is not a delivery',
        );
    }

    /**
     * `delivered()` asks WHICH channel accepted, not HOW MANY failed.
     *
     * The docblock says so — *"comparing names says what is meant"* — and round 8 found nothing
     * pinning it: replacing the by-name walk with `count($failures) < count($this->counting)` left
     * the whole suite green, because the ledger case reverts to arithmetic over `$usable`, which
     * the `$counting` set alone already catches.
     *
     * The discriminating case is a FAILING channel that does not count. Arithmetic sees one
     * failure against one counting channel and says undelivered — so a failed console write would
     * report a successful ntfy push as lost, and that flat would be re-announced every fifteen
     * minutes for ever.
     */
    public function testDeliveredAsksWhichChannelAcceptedNotHowManyFailed(): void
    {
        $notifier = new Notifier([$this->workingChannel('ntfy'), $this->failingChannel('console')]);

        $failures = $notifier->send($this->anyNotification());

        self::assertCount(1, $failures, 'the console write failed');
        self::assertTrue(
            $notifier->delivered($failures),
            'ntfy accepted it, so it was delivered — a failure on a channel that does not count '
            . 'must not make a real push read as lost',
        );
    }

    /**
     * The other direction, and the reason console is not simply dropped from the send list: it is
     * what makes `scout run --once` demonstrable. It still receives every notification; it just
     * does not vote on whether one was delivered.
     */
    public function testConsoleIsStillWrittenToEvenThoughItDoesNotCount(): void
    {
        $stream = $this->tempStream();
        $notifier = new Notifier([$this->failingChannel('ntfy'), new ConsoleChannel($stream)]);

        $notifier->send($this->anyNotification());

        rewind($stream);
        self::assertStringContainsString('Sartrouville', (string) stream_get_contents($stream));
    }

    // ---------------------------------------------------------------- delivery

    public function testAFailedSendIsReportedRatherThanSwallowed(): void
    {
        // The hole Q28 closes: a delivery failure that returns silently means the run reports
        // success, the listing is marked notified, and the flat is gone.
        $notifier = new Notifier([$this->failingChannel('ntfy')]);
        $failures = $notifier->send($this->anyNotification());

        self::assertCount(1, $failures);
        self::assertFalse($notifier->delivered($failures));
    }

    public function testOneChannelFailingDoesNotPreventTheOtherFromDelivering(): void
    {
        // Both survivors must be remote: a console print is not what makes this true.
        $notifier = new Notifier([$this->failingChannel('ntfy'), $this->workingChannel('email')]);

        $failures = $notifier->send($this->anyNotification());

        self::assertCount(1, $failures, 'the working channel was still attempted');
        self::assertTrue($notifier->delivered($failures));
    }

    public function testAChannelThrowingSomethingUnexpectedIsStillADeliveryFailure(): void
    {
        // It must not escape and abort the run: the next channel might have worked, and the caller
        // needs to know this listing was not delivered so it can retry.
        $notifier = new Notifier([new class implements Channel {
            public function name(): string
            {
                return 'wild';
            }

            public function check(): ?string
            {
                return null;
            }

            public function reachesRecipient(): bool
            {
                return true;
            }

            public function describe(): string
            {
                return 'test double';
            }

            public function send(Notification $notification): void
            {
                throw new \LogicException('something nobody anticipated');
            }
        }]);

        $failures = $notifier->send($this->anyNotification());

        self::assertCount(1, $failures);
        self::assertFalse($notifier->delivered($failures));
    }

    // ---------------------------------------------------------------- secrets

    public function testTheNtfyTopicIsMaskedEvenOnASelfHostedServer(): void
    {
        // THE LEAK a review found. The topic is a secret that travels as a URL PATH SEGMENT, so
        // there is no `topic=` to anchor a name-based rule on. The pattern rule covers the default
        // `ntfy.*` host and leaks the moment the server is self-hosted under any other name —
        // which .env.example exists to permit.
        $leaked = Redact::text('echec POST https://push.mondomaine.fr/appart-9f3a2b : timeout');
        self::assertStringContainsString('appart-9f3a2b', (string) $leaked, 'the pattern rule alone cannot see this');

        $masked = Redact::text('echec POST https://push.mondomaine.fr/appart-9f3a2b : timeout', ['appart-9f3a2b']);
        self::assertStringNotContainsString('appart-9f3a2b', (string) $masked);
    }

    public function testAChannelErrorMasksTheLiteralsItWasGiven(): void
    {
        $e = new ChannelError('ntfy', 'POST https://push.example.test/appart-9f3a2b failed', null, ['appart-9f3a2b']);

        self::assertStringNotContainsString('appart-9f3a2b', $e->getMessage());
        self::assertStringContainsString('ntfy:', $e->getMessage());
    }

    public function testAnNtfyStatusCodeSurvivesMasking(): void
    {
        // A 401 and a 404 mean very different things — wrong token vs wrong topic — and both are
        // actionable. Masking that ate the diagnostic would be worse than the leak it prevents.
        $e = new ChannelError('ntfy', 'server answered HTTP 401', null, ['appart-9f3a2b']);

        self::assertStringContainsString('401', $e->getMessage());
    }

    public function testAnUnconfiguredNtfyChannelRefusesAtCheckRatherThanAtSend(): void
    {
        $problem = (new NtfyChannel('', topicKey: 'RENT_NTFY_TOPIC'))->check();

        self::assertNotNull($problem);
        self::assertStringContainsString('RENT_NTFY_TOPIC', (string) $problem, 'the refusal names the key the domain read');
        self::assertStringContainsString('secret', (string) $problem);
    }

    public function testNtfyIsBehindTheOfflineTripwireLikeEveryOtherOutboundPath(): void
    {
        // `tests/bootstrap.php` sets SCOUT_OFFLINE=1 for the whole suite and calls it "the
        // backstop for the ones that are not given fakes". This channel drives libcurl DIRECTLY, so
        // it never passed CurlHttpClient's funnel — and its default server is a third party while
        // its topic is a documented secret. A review panel set the flag and watched it resolve and
        // dial a non-loopback host on 2026-08-24.
        // THREE TOPIC SHAPES, and the last two are the ones that matter. `Redact` masks literals by
        // `str_replace`, so a topic containing a character `rawurlencode` touches never matches the
        // encoded form in the message — and it deliberately ignores literals under four characters,
        // since masking those would eat ordinary words. A review panel leaked both on 2026-08-24.
        // The fix is not to put the secret in the string: the refusal names the SERVER.
        foreach (['a-secret-topic', 'rw secret topic', 'ab1'] as $topic) {
            $channel = new NtfyChannel($topic, 'https://rw-offline-probe.example.invalid');

            try {
                $channel->send(new Notification(
                    kind: NotificationKind::HEARTBEAT,
                    priority: Priority::LOW,
                    title: 'probe',
                ));
                self::fail('the offline tripwire did not fire — this channel can reach the network from a test');
            } catch (ChannelError $e) {
                self::assertStringContainsString('SCOUT_OFFLINE', $e->getMessage());
                self::assertStringNotContainsString($topic, $e->getMessage(), 'the topic must not appear');
                self::assertStringNotContainsString(
                    rawurlencode($topic),
                    $e->getMessage(),
                    'nor its url-encoded form, which is what the message used to carry',
                );
            }
        }
    }

    public function testTheOfflineTripwireStillAllowsLoopback(): void
    {
        // The counterweight, and it is load-bearing rather than decorative: a scripted server on
        // 127.0.0.1 is how this project proves what only a real socket can — that the honest
        // User-Agent crosses the wire, that SMTP refuses a credential without STARTTLS, and that a
        // failed delivery marks nothing. Refusing loopback would delete that evidence to enforce a
        // rule it does not break. Port 1 has nothing listening, so this fails at the socket rather
        // than at the tripwire — which is the distinction being asserted.
        $channel = new NtfyChannel('topic', 'http://127.0.0.1:1');

        try {
            $channel->send(new Notification(
                kind: NotificationKind::HEARTBEAT,
                priority: Priority::LOW,
                title: 'probe',
            ));
            self::fail('expected a connection failure');
        } catch (ChannelError $e) {
            self::assertStringNotContainsString('SCOUT_OFFLINE', $e->getMessage());
        }
    }

    public function testTheMailTransportsAreBehindTheOfflineTripwireToo(): void
    {
        // FOUR EGRESS POINTS, NOT ONE. `Core\Offline` was introduced saying it refused "every
        // outbound request" while guarding two: `CurlHttpClient` and `NtfyChannel`. SMTP and IMAP
        // open RAW SOCKETS, so neither passed the funnel, and a review panel watched both dial a
        // non-loopback host with the flag set. SMTP sends `AUTH LOGIN` with `SMTP_PASSWORD`; IMAP is
        // worse still, being the PRIMARY ingestion path under hard rule 4 and sending a cleartext
        // password to a host read from `.env`.
        //
        // TEST-NET-1 (RFC 5737) is used deliberately: nothing there is routable, so a failure that
        // is NOT the tripwire's sentence would prove the guard never ran.
        $transport = new SmtpTransport(
            host: '192.0.2.1',
            port: 587,
            user: 'someone@example.test',
            password: 'not-a-real-password',
            from: 'rent-watch@example.test',
        );

        try {
            $transport->send('to@example.test', 'subject', 'body', []);
            self::fail('SMTP reached the network from a test');
        } catch (ChannelError $e) {
            self::assertStringContainsString('SCOUT_OFFLINE', $e->getMessage());
            self::assertStringNotContainsString('not-a-real-password', $e->getMessage());
        }
    }

    public function testSendmailIsRefusedOfflineBecauseALocalMtaMayRelay(): void
    {
        // The fourth. `mail()` has no host to inspect, so there is no loopback exemption to grant —
        // the local MTA may relay anywhere, and a test that reaches here has already lost control of
        // where the message goes.
        try {
            (new SendmailTransport())->send('to@example.test', 'subject', 'body', []);
            self::fail('sendmail handed a message to the local MTA from a test');
        } catch (ChannelError $e) {
            self::assertStringContainsString('SCOUT_OFFLINE', $e->getMessage());
        }
    }

    public function testNtfyRefusesANonHttpServer(): void
    {
        self::assertNotNull((new NtfyChannel('topic', 'ntfy.example.test'))->check());
    }

    // ---------------------------------------------------------------- helpers

    private function anyNotification(): Notification
    {
        return (new Formatter())->match($this->listing(), Verdict::matched(70, ['test'], false));
    }

    /** @return resource */
    private function tempStream()
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        return $stream;
    }

    private function brokenChannel(string $name, string $problem): Channel
    {
        return new class($name, $problem) implements Channel {
            public function __construct(private readonly string $n, private readonly string $p) {}

            public function name(): string
            {
                return $this->n;
            }

            public function check(): ?string
            {
                return $this->p;
            }

            public function reachesRecipient(): bool
            {
                return $this->n !== 'console';
            }

            public function describe(): string
            {
                return 'test double';
            }

            public function send(Notification $notification): void
            {
                throw new ChannelError($this->n, 'must not be reached');
            }
        };
    }

    /** A channel that is remote (i.e. not `console`) and always succeeds. */
    private function workingChannel(string $name): Channel
    {
        return new class($name) implements Channel {
            public function __construct(private readonly string $n) {}

            public function name(): string
            {
                return $this->n;
            }

            public function check(): ?string
            {
                return null;
            }

            public function reachesRecipient(): bool
            {
                return $this->n !== 'console';
            }

            public function describe(): string
            {
                return 'test double';
            }

            public function send(Notification $notification): void {}
        };
    }

    private function failingChannel(string $name): Channel
    {
        return new class($name) implements Channel {
            public function __construct(private readonly string $n) {}

            public function name(): string
            {
                return $this->n;
            }

            public function check(): ?string
            {
                return null;
            }

            public function reachesRecipient(): bool
            {
                return $this->n !== 'console';
            }

            public function describe(): string
            {
                return 'test double';
            }

            public function send(Notification $notification): void
            {
                throw new ChannelError($this->n, 'the network went away');
            }
        };
    }

    public function testEmailSubjectAndFromCannotSmuggleAHeaderFromListingText(): void
    {
        // The structural twin of the ntfy Click finding: EmailChannel builds the Subject from the
        // landlord-controlled title and the From from config, both through headerSafe — but nothing
        // exercised that guard, so it was silently removable (a round-4 panel finding). The
        // transports (Smtp/File/Sendmail) build header lines raw and rely on this funnel. A CRLF in
        // the title is the classic Bcc-injection vector; it must not become its own header line.
        $dir = sys_get_temp_dir() . '/rentwatch-email-inj-' . bin2hex(random_bytes(6));

        // The From is separately validated by check() (its `\s` rejects a CRLF sender), so the
        // real injection surface is the Subject, built from the landlord-controlled title and
        // guarded ONLY by headerSafe. That is the guard under test.
        $channel = new EmailChannel(
            'moi@example.test',
            'rent-watch@localhost',
            '[rent-watch]',
            new FileTransport($dir),
        );

        $channel->send(new Notification(
            NotificationKind::MATCH,
            Priority::HIGH,
            "T4 a Chatou\r\nBcc: attacker@example.test\r\nSubject: forged",
            ['score 82'],
            'https://example.test/annonce/1',
        ));

        $files = glob($dir . '/*.eml') ?: [];
        self::assertCount(1, $files);
        $eml = (string) file_get_contents($files[0]);

        // Header block ends at the first blank line; the body may legitimately carry anything.
        $headerBlock = substr($eml, 0, strpos($eml, "\r\n\r\n") ?: strlen($eml));

        // A real injection would create its own header LINE; the collapse leaves the forged text as
        // harmless inline content of the one Subject line, so line-start is the right test.
        foreach (explode("\r\n", $headerBlock) as $line) {
            self::assertFalse(str_starts_with(strtolower(trim($line)), 'bcc:'), 'no injected Bcc header line: ' . $line);
        }
        // Exactly one Subject line — a smuggled `Subject:` continuation would make two.
        self::assertSame(1, preg_match_all('~^Subject: ~m', str_replace("\r\n", "\n", $headerBlock)));

        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    public function testASenderWithATrailingNewlineIsRejected(): void
    {
        // The sender check must use the `D` anchor: without it PHP's `$` matches before a trailing
        // newline, so `"rent-watch@localhost\n"` would pass validation and reach `MAIL FROM:<…>` as
        // a bare LF on the SMTP command line — the same trailing-newline hole closed for the header
        // token in the HTTP guards.
        $problem = (new EmailChannel(
            'moi@example.test',
            "rent-watch@localhost\n",
            '[rent-watch]',
            new FileTransport(sys_get_temp_dir() . '/rentwatch-unused-' . bin2hex(random_bytes(4))),
        ))->check();

        self::assertNotNull($problem);
        self::assertStringContainsString('sender address is not valid', (string) $problem);
    }
}
