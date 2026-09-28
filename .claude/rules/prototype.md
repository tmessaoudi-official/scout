---
paths:
  - "prototype/**"
---

# scout gotchas — prototype

Moved verbatim from CLAUDE.md § "Gotchas & pitfalls" on 2026-09-28 (review-remediation 5.4, /rules-split). Scope: the Python prototype's known gaps — none of them to be ported. New lessons for this area go HERE, not into CLAUDE.md. A § reference names a heading in CLAUDE.md or in docs/ENGINEERING-NOTES.md.

- **`prototype/scout.py` has no tenure classifier at all.** It will happily surface PLAI and PLUS
  listings. It is reference material for the field-mapping and adapter shape only — treat its filtering
  logic as incomplete, not as a baseline to preserve.
- The prototype's commune matching is a substring search over `commune + cp + title + raw_text`. That
  over-matches: a Paris listing mentioning "proche Chatou" passes the commune filter.
- The prototype's `(l.rooms or 0) < self.min_rooms` **disqualifies an unknown room count**. Same for
  surface. That is the `None`-is-not-zero bug (hard rule 9) in its natural habitat.
- The prototype swallows every per-source exception and `continue`s — hard rule 3.
- The prototype's `max_floor` is a hard reject. **Ruled 2026-08-07 (Q5): floor and lift are score
  components only**, and more strongly than the spec asked — `max_floor` and `require_elevator` do
  not exist as config keys at all, so the prototype's behaviour cannot be reintroduced by editing a
  file. The high-floor penalty additionally requires the lift to be **explicitly absent**, never
  merely unmentioned: `null` is not `false` (hard rule 9), which is why it is its own score
  component rather than the negation of the bonus.
- `prototype/sources.yaml` mixes criteria, notification config and sources in one file. The PHP tree
  splits them per domain: `config/{rent,car,job}/{criteria,sources}.json`.
- `ruff` **is** available in this container, and although the PHP side now has a `composer.json`
  there is still no Python manifest — so
  `.claude/hooks/lint-on-write.sh` is live and will report on `prototype/scout.py`. Those findings are
  known and deliberately unfixed: the prototype is kept verbatim as received. Since 2026-09-28 they
  reach Claude directly (the hook's `additionalContext`), so they arrive after any edit there — they
  still stay unfixed.
