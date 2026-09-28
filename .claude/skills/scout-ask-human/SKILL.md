---
name: scout-ask-human
description: >
  scout's additions to the global /ask-human question protocol — its mandatory cases (scope and
  ranking decisions, live notifications, the excluded-tenure and 0.6 invariants) and a worked
  example. The protocol itself is global ask-human § Question quality.
user-invocable: true
---

<!-- THINNED 2026-09-28 (review-remediation 7.6): the shared question-quality protocol (five parts,
  shape, non-negotiable rules, when mandatory / not) moved to the global `ask-human` skill,
  § "Question quality"; this file keeps only what is specific to scout. History: AskUserQuestion was
  banned in the cloud-container era (it failed silently there) and re-inverted 2026-08-18
  (de-containerization ruling); renamed ask-human → scout-ask-human the same day (a repo skill may not share
  a global skill's name). The full pre-thinning text is in git history. -->

## --help

> If ARGUMENTS contains `--help`: output the text below verbatim, then STOP — do not execute any other steps.
>
> ```
> /scout-ask-human — scout's additions to the global /ask-human question protocol:
>     its mandatory-question cases and a worked example.
>
> No flags — invoked automatically by Claude whenever a decision belongs to the developer.
> ```

---

# Question protocol — scout additions

The protocol — the five required parts, the non-negotiable rules, when a question is mandatory and
when it is not — is the global `/ask-human` skill, § "Question quality". Whether a question stops
the turn or is shown and answered with the recommended option is the GLOBAL `~/.claude/CLAUDE.md` § "Mode — spec or autonomous". This file adds
only what is specific to this repo.

## When a question is mandatory here

- Any **user-visible product decision** — which communes or filters are in scope, what a notification
  says and how it is ranked, which tenure classes are searched at all, which sources are enabled.
  Those are the developer's, made interactively, never ruled alone.
- Any **irreversible or outward-facing action** — a force-push, rewriting published git history, a
  schema migration run against the real listings database, sending a live notification to the
  developer's phone, subscribing or reconfiguring the alert mailbox, or a first request to a landlord
  site from this machine. Note that ordinary `git add` / `git commit` / `git push` are
  **autonomously authorised** here (CLAUDE.md § "Git autonomy") and must NOT be asked about.
- Any **change to a documented invariant or a declared ceiling** — the excluded-tenure set, the 0.6
  fail-closed confidence threshold, where `UNKNOWN` is routed, the boundary between a hard
  disqualifier and a score penalty, the `Source` adapter contract, or the opt-in gate on portal
  scraping. Weakening one of these is a product decision, not an implementation detail — and for the
  social-housing exclusion specifically, the answer is that it does not get weakened at all
  (CLAUDE.md §1); ask about *how* to satisfy it, never *whether* to.
- A **certification loop that hits its cap** (CLAUDE.md § "Certification ladder": 5 rounds with findings still open → ask, never
  silently proceed).

The global cases (two readings leading to materially different work, …) apply as well.

## Worked example

```
## Question — is PLS in scope, or excluded with PLAI and PLUS?

The tenure classifier needs this before it can have a default, and the answer changes which
sources are worth building at all. PLS is the top tier of *social* financing — its income
ceilings are high and landlords routinely market it on the same page as intermediate stock — so
it WAS the one genuinely ambiguous class in the glossary — Q4 settled it as never on 2026-08-06, and this example is kept for its shape, not its content. PLAI and PLUS are settled (never), LLI
is settled (always). This is an eligibility question, not a preference, and I cannot answer it
from the repo.

Today, on a real CDC Habitat result set, the same page returns all three:

    "T4 82m² — financement: PLAI"  → excluded, no ambiguity
    "T4 78m² — financement: PLS"   → ??? currently classified SOCIAL and dropped silently
    "T4 80m² — logement locatif intermédiaire" → LLI, notified

    With PLS excluded, roughly a third of CDC Habitat's T4 stock never reaches you.
    With PLS included, some of what you get needs an SNE number you may not have.

**Option 1 — exclude PLS, treat it as social (recommended).** Keeps the excluded set aligned
   with "requires SNE registration and a commission d'attribution", which is the practical test
   for whether you can actually apply.
   After: PLS listings are dropped silently and logged, exactly like PLAI and PLUS. Highest
   precision, and every notification is one you can act on.

**Option 2 — surface PLS in the "à vérifier" digest only.** Treats it as neither a match nor a
   silent drop: it never reaches the high-priority notification, but you can review the batch.
   After: you see PLS stock without it competing with LLI for your attention. Costs you a daily
   digest to skim, and the digest is where genuinely-unclassified listings already go.

**Option 3 — include PLS as a full match, ranked below LLI.** Maximum recall.
   After: notification volume rises materially and some alerts are for units you cannot get
   without an SNE number — which is the failure mode that makes the tool untrustworthy.

**Option 4 — none of these / challenge the premise.** For example: include PLS only for specific
   landlords where you know the allocation route, or only above a rent threshold that implies a
   high ceiling — say so if that is the target.

I'll wait for your answer before doing anything else.
```
