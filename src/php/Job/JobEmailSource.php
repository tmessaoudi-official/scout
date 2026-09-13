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
 * A job portal's alert email, read from its HTML part — LinkedIn is source #1.
 *
 * **The HTML part, because only it carries the facts.** LinkedIn sends both alternatives, and only
 * the HTML states each card's work mode and pay line (measured on 20 captures, 115 cards). The
 * body path prefers text/plain for reasons that stay true, so this reads `EmailMessage::htmlText`.
 *
 * **A card starts at its first view link and ends at the next distinct id.** Each card links its
 * logo and its title to the same `/jobs/view/<id>/` URL, so a doubled link is one card. Nothing
 * before the first card link is read: the alert's preheader quotes the SUBSCRIBER'S pay filter
 * (`Salaire entre …`), and reading it as a card's pay is PAP's criteria-line defect again. Nothing
 * from `footer_marker` on is read either: that block repeats cards from other alerts.
 *
 * Stated fragility: segmentation relies on the logo link PRECEDING the title. If LinkedIn drops the
 * logo link, each title lands in the card above and every card's second line stops matching
 * `place_pattern` — a 100 % miss, which `health()` reports as a template change.
 *
 * **Counted, per pass:** `card_link_pattern` and `footer_marker` per claimed message, `place_pattern`
 * per card, and `mode_word` per card whose place line carries a parenthesis (a card stating no mode
 * is legitimate and must not dilute the ratio). `pay_pattern` is deliberately NOT counted: 7 of 115
 * real cards state pay, so it would warn on every pass.
 *
 * Hard rule 9 throughout: `observedAt` is the message's send instant, `publishedAt` stays null
 * (a card carries no date), and an unknown mode word leaves the mode null and the text in the location.
 */
final readonly class JobEmailSource implements AcknowledgesMessages, CountsPatternMisses, JobSource, FeedFreshness
{
    /** The mode words LinkedIn writes, lower-cased, to the canonical `JobListing::WORK_MODES`. */
    private const array MODES = ['hybride' => 'hybrid', 'à distance' => 'remote', 'sur site' => 'onsite'];

    /** In-band markers for the two kinds of link, which no card text can contain. */
    private const string CARD = "\x00CARD ";
    private const string LINK = "\x00LINK";

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
        $out = [];

        foreach ($messages as $position => $raw) {
            $message = EmailMessage::parse($raw);
            if ($from !== '' && !str_contains(strtolower($message->from()), $from)) {
                continue;
            }

            // CLAIMED: past the sender filter, this message is ours whatever it yields. The mark is
            // written by acknowledge(), after the store has recorded the pass.
            $this->mailbox->claim($position);

            $observedAt = $message->sentAt();
            $seen = [];
            foreach ($this->cards($message) as [$id, $url, $lines]) {
                // No staging (`PatternMissLog::begin()`): `offer()` refuses a card only BEFORE any
                // pattern runs, so nothing counted belongs to furniture. A repeated card still
                // counts — its patterns ran on real card text.
                $offer = $this->offer($id, $url, $lines, $observedAt);

                if ($offer === null) {
                    continue;
                }
                if (isset($seen[$id])) {
                    ($this->warn)?->__invoke(sprintf('%s : offre %s en double dans un même courrier — une seule gardée', $this->name(), $id));
                    continue;
                }
                $seen[$id] = true;
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
     * The cards of one message, in order: `[id, url, non-empty normalised lines]`.
     *
     * @return list<array{0: string, 1: string, 2: list<string>}>
     */
    private function cards(EmailMessage $message): array
    {
        $linkPattern = $this->definition->param('card_link_pattern');
        if ($linkPattern === null) {
            return [];
        }

        $text = $message->htmlText;
        $marker = $this->definition->param('footer_marker');
        if ($marker !== null) {
            // DEGRADED, not silent: a missing marker reads to the end, where repeated cards are
            // warned about, and the miss is counted.
            $cut = strpos($text, $marker);
            $this->patternMisses->record('footer_marker', $cut !== false);
            if ($cut !== false) {
                $text = substr($text, 0, $cut);
            }
        }

        // A null here is a regex engine failure on this message, and it yields no card link — which
        // the count below records, so it cannot pass for a quiet market.
        $text = preg_replace_callback(
            '~https?://\S+~',
            static fn (array $m): string => preg_match($linkPattern, $m[0], $g) === 1 && ($g[1] ?? '') !== ''
                ? "\n" . self::CARD . $g[1] . ' ' . (preg_replace('~[?#].*$~', '', $m[0]) ?? $m[0]) . "\n"
                : "\n" . self::LINK . "\n",
            $text,
        ) ?? '';

        $cards = [];
        foreach (explode("\n", $text) as $raw) {
            if (str_starts_with($raw, self::CARD)) {
                [$id, $url] = explode(' ', substr($raw, strlen(self::CARD)), 2);
                $last = array_key_last($cards);
                // The SAME id continues a card only across its title: logo link, title, title link.
                // Once the card holds more than that line, the id is a repeated card, never a
                // continuation that would swallow the text in between as labels.
                if ($last === null || $cards[$last][0] !== $id || count($cards[$last][2]) > 1) {
                    $cards[] = [$id, $url, []];
                }

                continue;
            }
            if ($raw === self::LINK) {
                continue;
            }

            $line = trim(preg_replace('~[\x{034F}\s]+~u', ' ', $raw) ?? '');
            $last = array_key_last($cards);
            if ($line !== '' && $last !== null) {
                $cards[$last][2][] = $line;
            }
        }

        $this->patternMisses->record('card_link_pattern', $cards !== []);

        return $cards;
    }

    /**
     * One card's lines: the title, then `company · location (mode)`, then the pay line and labels.
     *
     * @param list<string> $lines
     */
    private function offer(string $id, string $url, array $lines, ?string $observedAt): ?JobListing
    {
        // A link with no text under it is not a card.
        if ($lines === []) {
            return null;
        }

        $title = $lines[0];
        $rest = array_slice($lines, 1);
        $company = '';
        $location = '';
        $mode = null;

        $placePattern = $this->definition->param('place_pattern');
        if ($placePattern !== null) {
            $hit = isset($rest[0]) && preg_match($placePattern, $rest[0], $g) === 1;
            $this->patternMisses->record('place_pattern', $hit);

            if ($hit) {
                array_shift($rest);
                $company = trim($g['company'] ?? '');
                $location = trim($g['location'] ?? '');
                $word = trim($g['mode'] ?? '');

                if ($word !== '') {
                    $mode = self::MODES[mb_strtolower($word)] ?? null;
                    $this->patternMisses->record('mode_word', $mode !== null);
                    if ($mode === null) {
                        // Not a mode: the parenthesis belongs to the place, and the mode stays unknown.
                        $location = trim($location . ' (' . $word . ')');
                    }
                }
            }
        }

        $payText = null;
        $labels = [];
        $payPattern = $this->definition->param('pay_pattern');
        foreach ($rest as $line) {
            if ($payText === null && $payPattern !== null && preg_match($payPattern, $line) === 1) {
                $payText = $line;

                continue;
            }
            $labels[] = $line;
        }

        return new JobListing(
            sourceName: $this->name(),
            externalId: $id,
            title: $title,
            company: $company,
            location: $location,
            fields: $labels === [] ? [] : ['labels' => implode(' | ', $labels)],
            url: $url,
            workMode: $mode,
            payText: $payText,
            observedAt: $observedAt,
        );
    }
}
