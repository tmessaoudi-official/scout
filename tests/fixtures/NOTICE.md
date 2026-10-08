# Notice — `tests/fixtures/` is excluded from the MIT licence

The MIT licence in the repository's [`LICENSE`](../../LICENSE) covers the code. It does **not** cover this
directory, which is excluded as a whole.

This directory contains material captured from third-party websites and emails: listing pages, API
responses and alert messages published by landlords, portals and job boards. It is included solely so the
parsers can be tested offline against frozen payloads. No licence to that material is granted; its rights
remain with their owners. Captures in the current tree are scrubbed of personal data; see
`docs/OPEN-QUESTIONS.md` Q40 for the earlier commits that were scrubbed only after the fact.

The directory also contains synthetic test data written for this project (most of the tenure corpus, for
example). It is excluded together with the captures, so that the boundary is one path rather than a
per-file judgement.
