# Dashboard financial corrections — approved 2026-07-27

Four MEASURED corrections. No modelled assumptions: the 50% expense floor and the
founder-expense re-base were both dropped (bank statements superseded the floor;
founder figures stay exactly as recorded).

## Todo

- [ ] 1. Remove Sep/Oct-2025 duplicate revenue (−21,670). In `all` mode the
      dashboard does `manualRevenue + allCalculatedRevenue` with no exclusion, so
      the 9 orders paid inside the two `is_manual_mode` months are counted twice.
      Fix: exclude manual months from the CALCULATED order revenue everywhere it
      is combined with the manual figure.
- [ ] 2. Stripe fees as a real expense line — 49,959 MXN measured from the Stripe
      balance ledger, one row per month. New `fees` category (the column is
      `string(50)`, so no migration) added to the dashboard whitelist.
- [ ] 3. PR service fees dated on `paid_at`, not `created_at`. Use
      `COALESCE(paid_at, created_at)` — one paid PR has a null `paid_at` and must
      not be dropped.
- [ ] 4. Backfill Sep-2025 business expenses (4,000) — the month has zero rows
      while its manual metric claims 4,000.

## Explicitly NOT doing

- 50% operating-expense floor — superseded by actual HSBC outflows.
- Founder expense re-base (25k/50k) — stays as recorded, per Alex.
- Anything to the War Chest — it stays a separate cash metric.

## Verification

- [ ] All-time revenue drops by exactly 21,670.
- [ ] All-time expenses rise by exactly 49,959 + 4,000.
- [ ] Monthly service-fee revenue re-dates without losing the null-`paid_at` PR.
- [ ] Sanity-check each month before/after against the pre-change snapshot.
