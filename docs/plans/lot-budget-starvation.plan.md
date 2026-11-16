# lot-budget-starvation Plan

A lot-budget car source (`SitemapVehicleSource`, `AlcopaVehicleSource`) can spend its whole per-pass budget on lot
pages it cannot read and record the run as `ok`: `source_runs.item_count` is the INDEX's size, not the lots read. On
2026-11-16 Autohero did this for five passes (50 of 50 slots on the same 50 self-redirecting lots, `ok, 4022 items`);
see `docs/plans/architecture-review.plan.md` § Known issues and commit `be34bde`.

## Decisions Log
- [2026-11-16 11:32] AGREED: Starvation rule: a lot-budget source whose pass reached lot_budget_per_pass and read no lot escalates OK to WARN_DROP through PatternMissLog::escalate() with its own sentence; 'every attempt failed' was rejected because it flaps on dead lots retried every quiet pass (497/520 passes measured)
- [2026-11-16 11:33] AGREED: This plan lives in its own file; architecture-review Known issues points here

## Formal Plan

**Rule.** A pass whose lot fetches reached `lot_budget_per_pass` and read NO lot is STARVED. Measured why not "every
attempt failed": 497 of 520 Autohero passes from 2026-09-27 to 2026-10-08 recorded no new lot, and the 4 lots that
still fail after `be34bde` are retried every pass, so that rule would WARN on nearly every quiet pass and send a
recovery at each burst (`RunStore::clearAlerts()` deletes on any OK pass). The budget rule fires on 50/50 and stays
silent on 4 attempts.

**Mechanism.** The source records the starvation fact on its `PatternMissLog` (reset per pass, not persisted, like
every miss count); `PatternMissLog::escalate()` stays the ONE escalation and upgrades `OK` to `WARN_DROP` with its
own sentence (not the template-change wording, which is wrong for a 302): *les N lots tentés sur cette passe
n'ont donné aucune annonce (budget épuisé, aucun lot lu)*. `VehiclePipeline::alertOnHealth()` pushes it once per cooldown and
sends one recovery when a pass reads a lot.

**Tests first.** Red: a starved Autohero pass reads `WARN_DROP` with that sentence; the alcopa twin; a starved pass
through `VehiclePipeline` sends a source-health push. Counterweights (stay `ok`): dead lots without the budget
exhausted, zero attempts, a full budget with one lot read. Ledger: one case per source dropping the record, each
measured on scratch for changed-line count and WHICH test goes red.

**Stated costs.** A template change in a quiet period alerts only when unread lots accumulate to the budget (failed
lots are never recorded, so they do). Dead lots at or above the budget alert until they leave the index, which is
correct. Rent detail hydration (`listing_detail`) has a similar shape and is NOT covered.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | Starvation alert for lot-budget sources (TDD, three ledger cases) | M | done | 076ede8 | src/php/Core/PatternMissLog.php, src/php/Car/SitemapVehicleSource.php, src/php/Car/AlcopaVehicleSource.php |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
- Rent detail hydration is not covered by this rule (see Formal Plan, stated costs).
- `.claude/skills/domain-scraping-sources-health/SKILL.md` (zero-result table) and EXPERTISE-REFERENCE §8 do not yet name the starvation signal; add it at their next curated refresh (intake rule: no hand-append; the lesson is in `.claude/rules/health.md`).
