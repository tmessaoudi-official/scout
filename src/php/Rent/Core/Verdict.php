<?php

declare(strict_types=1);

namespace Scout\Rent\Core;

/**
 * What the criteria engine concluded about one classified listing.
 *
 * **Disqualifiers and score are two different mechanisms** (`CLAUDE.md` hard rule 8) and this object
 * keeps them apart rather than folding them into one number. A disqualified listing has no score at
 * all — not a score of zero, which would sort it alongside a genuine but poor match and invite a
 * caller to notify it anyway.
 *
 * Every field here exists to be shown. A notification that says "score 82" is not actionable; one
 * that says "82 — Sartrouville (top choice), 1450 € CC, 350 € under the ceiling, 88 m², lift" lets
 * the developer decide in three seconds whether to open the link.
 */
final readonly class Verdict
{
    /**
     * @param int|null          $score        0–100, or `null` when disqualified
     * @param list<string>      $reasons      human-readable, highest-value first — the notification body
     * @param string|null       $disqualifier the single rule that rejected it, or `null`
     * @param bool              $highPriority whether this earns an immediate high-priority push
     * @param DigestCause|null  $digestCause  why it landed in the *à vérifier* bin; `null` unless
     *                                        the outcome is `DIGEST`
     */
    private function __construct(
        public Outcome $outcome,
        public ?int $score,
        public array $reasons,
        public ?string $disqualifier,
        public bool $highPriority,
        public ?DigestCause $digestCause = null,
    ) {}

    /**
     * Rejected by a hard disqualifier. Logged only, never notified.
     *
     * The disqualifier is named because silent over-rejection is the one failure mode nobody can
     * see: nothing arrives, and that is indistinguishable from a quiet market. `scout run -v` prints
     * these, which is the only way a mis-scoped filter is ever noticed.
     */
    public static function rejected(string $disqualifier): self
    {
        return new self(Outcome::REJECT, null, [], $disqualifier, false);
    }

    /**
     * Goes to the "à vérifier" digest and only there.
     *
     * Carries the classifier's reasons so the digest entry says WHY it is doubtful — a digest of
     * bare links is one the developer stops opening.
     *
     * **`$cause` has no default, deliberately.** The bin used to have one entrance and the rollup
     * title spoke for every entry; it now has two, and a default would let a future third route
     * inherit the §1 regime clause by omission — exactly the shape {@see DigestCause} exists to
     * stop. Every caller states which bin this is.
     *
     * @param list<string> $reasons
     */
    public static function digest(array $reasons, DigestCause $cause): self
    {
        return new self(Outcome::DIGEST, null, $reasons, null, false, $cause);
    }

    /** The reason a match carries when none was recorded: a bare score cannot be judged. */
    public const string NO_REASON = 'aucune raison enregistrée pour ce score — à juger sur l\'annonce';

    /**
     * A match ALWAYS carries a reason (architecture review A-16, 2026-10-08). The drain rebuilds
     * one from a stored `signals_json` that decodes to nothing when it is damaged, and the brief's
     * contract is a score AND why; an empty list is replaced by a stated one, never thrown on,
     * because a throw here would block the push.
     *
     * @param list<string> $reasons
     */
    public static function matched(int $score, array $reasons, bool $highPriority): self
    {
        return new self(Outcome::MATCH, $score, count($reasons) > 0 ? $reasons : [self::NO_REASON], null, $highPriority);
    }

    public function isMatch(): bool
    {
        return $this->outcome === Outcome::MATCH;
    }
}
