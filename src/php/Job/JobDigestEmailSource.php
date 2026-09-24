<?php

declare(strict_types=1);

namespace Scout\Job;

use Scout\Adapters\AcknowledgesMessages;
use Scout\Adapters\FeedFreshness;
use Scout\Adapters\Mail\EmailMessage;
use Scout\Adapters\Mail\Mailbox;
use Scout\Adapters\Mail\MailboxError;
use Scout\Adapters\SourceError;
use Scout\Core\CountsPatternMisses;
use Scout\Core\PatternMissLog;
use Scout\Core\SourceHealth;

/**
 * A job portal's DIGEST alert — Free-Work (source #2, its text/plain part), HelloWork and Apec (both
 * HTML-only, so the body is the stripped HTML with each anchor's URL written into it).
 *
 * **One configured `card_pattern` per card**, with the named groups `title` and `url`, one of `facts`
 * or `place`, and optionally `contracts`, `company` and `pay`, because here the card's link does not
 * open the card: the LinkedIn reader, which
 * starts a card at its link, cannot read this shape. The pattern is deliberately NOT line-anchored in
 * the shipped config — Free-Work glues the first card of every section onto the section header line,
 * and a line-anchored reader found 36 cards of 40 on the first real capture.
 *
 * **A `place` group is the location WHOLE.** HelloWork and Apec write `Suresnes - 92`, which the
 * ` - ` splitter below would read as the place `92`; so with a `place` group no facts segment is taken
 * as the place. A `pay` group goes into `payText` as written — HelloWork states its unit
 * (`55 000 - 65 000 € / an`), and {@see JobPay} reads it.
 *
 * **Facts are ` - `-separated segments, and the LAST one is the place.** A segment matching
 * `salary_pattern` is an annual salary and one matching `tjm_pattern` a day rate — the portal's own
 * convention, checked on a live offer page (`400-550 €⁄j`) — and each goes into `payText` with that
 * unit STATED (`salaire annuel …`, `TJM … par jour`), so {@see JobPay} stays the one reader of pay and
 * its plausibility bands still apply. Any other segment (`12 mois`) is a label, never guessed at.
 *
 * **Identity is `id_pattern`'s group 1 over the card URL**; the URL is kept without its query, which
 * is a campaign tag. **With `id_token_pattern`, the id is read from a base64url TOKEN instead**: its
 * `token` group is decoded (strictly — a token that does not decode is a miss) and `id_pattern` runs on
 * the decoded text, because HelloWork and Apec put the offer id nowhere else. When the decoded text
 * carries an http(s) URL (HelloWork: `<subscriber>🪢<offer URL>`), that URL without its query is the
 * listing's link, so the push opens the offer rather than a tracking click. When it carries none (Apec:
 * `p1=…&p2=<id>W…`), the card link is kept WHOLE: there the query is the link. A repeated id WITHIN one message is the template when the card text is identical
 * — four alert sections overlap by design — and is kept once in silence, since a warning every day is
 * furniture. The same id with DIFFERENT text is kept once (the first) and warned about.
 *
 * **Scope:** `from` and, when set, `subject_pattern`. The same sender mails profile reminders that
 * carry real offer links in another shape; an unclaimed message stays unread, which is the signal.
 *
 * **Counted, per pass:** `card_pattern` per claimed message, `id_token_pattern` per card when set,
 * and `id_pattern` per card whose token decoded — so a red names the half that failed. The two pay
 * patterns are not counted: many cards state no pay.
 *
 * Hard rule 9 throughout: `observedAt` is the message's send instant, `publishedAt` stays null (a
 * "last 24 hours" digest states no date per offer), the work mode stays null because no card states
 * one, and the company stays empty where the card names none (Free-Work).
 */
final readonly class JobDigestEmailSource implements AcknowledgesMessages, CountsPatternMisses, JobSource, FeedFreshness
{
    private const string SEPARATOR = ' - ';

    /** @param ?\Closure(string): void $warn */
    public function __construct(
        private JobSourceDefinition $definition,
        private JobStore $store,
        private Mailbox $mailbox,
        private ?\Closure $warn = null,
        private int $limit = 50,
        /** Mutable held by a `readonly` source: the property cannot be reassigned, the counter can be written. */
        private PatternMissLog $patternMisses = new PatternMissLog(),
    ) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    public function family(): string
    {
        return $this->definition->family;
    }

    public function host(): ?string
    {
        return null;
    }

    public function newestFeedItemAt(): ?string
    {
        return $this->mailbox->newestMessageAt();
    }

    public function fetch(): array
    {
        // A count never spans two fetches: the CLI builds sources once and the watch loop reuses them.
        $this->patternMisses->reset();

        try {
            $messages = $this->mailbox->fetchRecent($this->limit);
        } catch (MailboxError $e) {
            throw new SourceError($this->name(), $e->getMessage(), $e);
        }

        $from = strtolower((string) $this->definition->param('from'));
        $subjectPattern = $this->definition->param('subject_pattern');
        $out = [];

        foreach ($messages as $position => $raw) {
            $message = EmailMessage::parse($raw);
            if ($from !== '' && !str_contains(strtolower($message->from()), $from)) {
                continue;
            }
            if ($subjectPattern !== null && preg_match($subjectPattern, $message->subject()) !== 1) {
                continue;
            }

            // CLAIMED: past both filters, this message is ours whatever it yields.
            $this->mailbox->claim($position);

            $observedAt = $message->sentAt();
            /** @var array<string, string> $seen id => the card text first read under it */
            $seen = [];
            foreach ($this->cards($message->body) as $card) {
                $offer = $this->offer($card, $observedAt);
                if ($offer === null) {
                    continue;
                }
                if (isset($seen[$offer->externalId])) {
                    if ($seen[$offer->externalId] !== $card[0]) {
                        ($this->warn)?->__invoke(sprintf('%s : offre %s lue deux fois dans un même courrier avec un texte différent — la première gardée', $this->name(), $offer->externalId));
                    }

                    continue;
                }
                $seen[$offer->externalId] = $card[0];
                $out[] = $offer;
            }
        }

        return $out;
    }

    public function acknowledge(): void
    {
        try {
            $this->mailbox->acknowledge();
        } catch (MailboxError $e) {
            throw new SourceError($this->name(), 'marquage des courriers traités refusé — ' . $e->getMessage(), $e);
        }
    }

    public function health(?string $nowIso = null): SourceHealth
    {
        return $this->patternMisses->escalate(
            $this->store->runs()->health($this->name(), $nowIso, $this->definition->feedSilentDays),
        );
    }

    public function patternMisses(): PatternMissLog
    {
        return $this->patternMisses;
    }

    /**
     * Every card match in the text part: `[whole match, groups]`.
     *
     * @return list<array{0: string, 1: array<string, string>}>
     */
    private function cards(string $body): array
    {
        $pattern = $this->definition->param('card_pattern');
        if ($pattern === null) {
            return [];
        }

        // A false here is a regex engine failure on this message; it yields no card, which the count
        // below records, so it cannot pass for a quiet day.
        $found = preg_match_all($pattern, $body, $matches, PREG_SET_ORDER) ?: 0;
        $this->patternMisses->record('card_pattern', $found > 0);

        $cards = [];
        foreach ($found > 0 ? $matches : [] as $m) {
            $groups = [];
            foreach (['title', 'contracts', 'facts', 'url', 'company', 'place', 'pay'] as $group) {
                $groups[$group] = trim(preg_replace('~\s+~u', ' ', (string) ($m[$group] ?? '')) ?? '');
            }
            $cards[] = [$m[0], $groups];
        }

        return $cards;
    }

    /** @param array{0: string, 1: array<string, string>} $card */
    private function offer(array $card, ?string $observedAt): ?JobListing
    {
        $g = $card[1];
        $url = preg_replace('~[?#].*$~', '', $g['url']) ?? $g['url'];
        $idSubject = $url;

        $tokenPattern = $this->definition->param('id_token_pattern');
        if ($tokenPattern !== null && $tokenPattern !== '') {
            // Matched on the link AS WRITTEN: Apec's token is in the query the line above strips.
            $decoded = preg_match($tokenPattern, $g['url'], $t) === 1
                ? base64_decode(strtr((string) ($t['token'] ?? ''), '-_', '+/'), true)
                : false;
            $tokenHit = is_string($decoded) && $decoded !== '';
            $this->patternMisses->record('id_token_pattern', $tokenHit);
            if (!$tokenHit) {
                return null;
            }
            $idSubject = $decoded;
            $url = preg_match('~https?://[^\s?#]+~', $decoded, $direct) === 1 ? $direct[0] : $g['url'];
        }

        $idPattern = (string) $this->definition->param('id_pattern');
        $hit = preg_match($idPattern, $idSubject, $idMatch) === 1 && trim($idMatch[1] ?? '') !== '';
        $this->patternMisses->record('id_pattern', $hit);
        if (!$hit || $g['title'] === '') {
            return null;
        }

        $segments = array_values(array_filter(array_map('trim', explode(self::SEPARATOR, $g['facts'])), static fn (string $s): bool => $s !== ''));
        // A `place` group is the whole location: `Suresnes - 92` through the splitter would be `92`.
        $location = $g['place'] !== '' ? $g['place'] : ($segments === [] ? '' : (string) array_pop($segments));

        $salaryPattern = $this->definition->param('salary_pattern');
        $tjmPattern = $this->definition->param('tjm_pattern');
        $pay = $g['pay'] === '' ? [] : [$g['pay']];
        $labels = [];
        foreach ($segments as $segment) {
            if ($salaryPattern !== null && preg_match($salaryPattern, $segment) === 1) {
                $pay[] = 'salaire annuel ' . $segment;
            } elseif ($tjmPattern !== null && preg_match($tjmPattern, $segment) === 1) {
                $pay[] = 'TJM ' . $segment . ' par jour';
            } else {
                $labels[] = $segment;
            }
        }

        $contracts = array_values(array_filter(array_map('trim', explode(',', $g['contracts'])), static fn (string $c): bool => $c !== ''));

        return new JobListing(
            sourceName: $this->name(),
            externalId: trim($idMatch[1]),
            title: $g['title'],
            company: $g['company'],
            location: $location,
            fields: $labels === [] ? [] : ['labels' => implode(' | ', $labels)],
            url: $url,
            contracts: $contracts,
            payText: $pay === [] ? null : implode("\n", $pay),
            observedAt: $observedAt,
        );
    }
}
