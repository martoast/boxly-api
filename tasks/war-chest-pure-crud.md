# War Chest → pure CRUD + split Stripe into US / MX

## Goal

1. **Strip all automatic money movement.** No order payment, expense, or Stripe
   webhook ever touches a War Chest balance again. Balances are whatever the
   admin types in.
2. **Two Stripe rows** — `Stripe US` (the account currently in `.env` as the
   main/normal one) and `Stripe MX` — both purely manual.

Everything else about the feature (accounts list, goals, progress bars, per-account
checkbook ledger, manual entries) stays exactly as it is.

---

## Why `payment_method` has to go

The `war_chest_accounts.payment_method` column exists **only** as the routing key
for the automatic crediting/debiting we're removing. It also carries a **UNIQUE
index** — which is precisely what blocks having two Stripe rows today.

So removing the routing concept is what unblocks the US/MX split. After this the
column has no meaning, so it goes with the logic that used it.

---

## Todo

### API — remove the automatic hooks

- [ ] `app/Http/Controllers/AdminOrderController.php`
  - [ ] Delete the `WarChestAccount::applyDelta(...)` block in
        `markConsolidationPaid()` (~line 473)
  - [ ] Delete the `WarChestAccount::applyDelta(...)` block in
        `updateStatus()` (~line 565)
  - [ ] Leave `paid_location` writes alone — still useful reporting data
- [ ] `app/Http/Controllers/AdminBusinessExpenseController.php`
  - [ ] Delete `addExpenseToWarChest()` + `removeExpenseFromWarChest()` (private,
        ~lines 278–311)
  - [ ] Delete their 4 call sites in `store()`, `update()` (x2), `destroy()`
  - [ ] Leave the expense's own `payment_method` field alone — expense-level
        reporting data, unrelated
- [ ] Confirm nothing else calls into it (already verified: `AdminOrderManagementController`,
      `AdminPurchaseRequestController`, `AdminQuoteController`, `CheckoutController`,
      `StripeWebhookController` never did)

### API — strip the routing concept from the model

- [ ] `app/Models/WarChestAccount.php`
  - [ ] Delete `forMethod()` and `applyDelta()` (now unused)
  - [ ] Keep `move()` — the controller's manual-entry path uses it
  - [ ] Remove `payment_method` from `$fillable`, drop the now-unused `Log` import
- [ ] `app/Http/Controllers/AdminWarChestController.php`
  - [ ] Remove `payment_method` from `rules()` (incl. the `Rule::unique` and the
        now-unused `Rule` import)
  - [ ] Remove it from the `store()` create payload
  - [ ] Relax `destroyTransaction()`'s source_type guard so **any** ledger entry
        can be deleted — lets you clean out the historical auto-generated
        `order`/`expense` rows by hand. (Pure CRUD, your call what stays.)

### API — migrations

- [ ] New migration: drop `payment_method` from `war_chest_accounts`
      (`down()` restores the nullable unique column)
- [ ] New migration: rename the seeded `Stripe` account → `Stripe US`, and insert
      a `Stripe MX` account (balance 0, target 0, `mxn`, sort_order after it).
      Re-sort so the list reads: Stripe US, Stripe MX, HSBC, NU.
      Idempotent — skip the insert if a `Stripe MX` row already exists.

### App — frontend

- [ ] `pages/app/admin/war-chest/index.vue`
  - [ ] Remove the "Linked payment method" picker + `routingMethod` / `methodNone`
        / `routingHint` translations from the create/edit modal
  - [ ] Remove the `paymentMethods` const and the `payment_method` badge on cards
  - [ ] Drop `payment_method` from the create/edit request bodies
- [ ] `pages/app/admin/war-chest/[id].vue`
  - [ ] Remove the `payment_method` badge in the header
  - [ ] Allow the delete button on every entry (drop the `canDelete` source_type
        filter, to match the relaxed API)

### Verify

- [ ] Mark an order paid with `paid_location: Stripe` → no War Chest movement,
      no new ledger row
- [ ] Create / edit / delete an expense with a payment method → no War Chest
      movement
- [ ] Pay a real Stripe box invoice → no War Chest movement (was already true)
- [ ] War Chest page: create, rename, edit balance + goal, delete an account
- [ ] Checkbook: add a manual in/out entry, delete any entry, month filter,
      running-balance still reconciles to `current_balance`

---

## Notes / open items

- **Existing balances are left untouched.** Whatever the accounts read today
  stays; adjust them by hand after deploy. Old `order`/`expense` ledger rows also
  stay until you delete them.
- **`adjustment` + `opening` entries keep working** — editing a balance still
  writes an `adjustment` row so the checkbook reconciles. That's the manual
  audit trail, not automation, so it stays.
- **Nothing is built on the Stripe API side.** No balance sync, no webhook hook,
  no fee handling — explicitly out of scope per your call.
- `Order::PAID_LOCATIONS` and `BusinessExpense::PAYMENT_METHODS` (both
  `['NU','HSBC','Stripe']`) are **left as-is** — they're independent of the War
  Chest and still drive expense/order reporting. Say the word if you want
  those split into US/MX too.

## Review

**Done. All todos complete, verified against the local Docker stack.**

### API — automation removed
- `AdminOrderController.php` — deleted both `applyDelta` blocks (`markConsolidationPaid`,
  `updateStatus`). `paid_location` is still written for reporting.
- `AdminBusinessExpenseController.php` — deleted `addExpenseToWarChest()` +
  `removeExpenseFromWarChest()` and all 4 call sites. The expense's own
  `payment_method` field is untouched.
- `WarChestAccount.php` — deleted `forMethod()` + `applyDelta()` and the now-unused
  `Log` import; dropped `payment_method` from `$fillable`. `move()` kept (manual entries).
- `AdminWarChestController.php` — `payment_method` out of `rules()` + `store()`,
  `Rule` import dropped, `rules()` no longer needs the `$ignoreId` arg.
  `destroyTransaction()` guard relaxed: any entry is deletable, balance still reversed.

Verified zero remaining references to `applyDelta` / `forMethod` /
`addExpenseToWarChest` / `removeExpenseFromWarChest` anywhere in `api/` or `cli/`.

### Migrations
- `2026_07_29_100000_drop_payment_method_from_war_chest_accounts_table` — drops the
  unique index then the column.
- `2026_07_29_110000_split_stripe_war_chest_accounts` — `Stripe` → `Stripe US`
  (keeps its balance + ledger), inserts `Stripe MX`, re-sorts to
  US / MX / HSBC / NU. Insert is idempotent. `down()` only deletes Stripe MX when it
  has no ledger history, so a rollback can't destroy recorded movements.

Both directions tested: migrate → verified → rollback restores the original 3 rows and
the column → migrate again. Note `down()` cannot restore the old `payment_method`
*values* (dropped data); it comes back NULL. Irrelevant now that routing is gone.

### App — frontend
- `index.vue` — removed the routing-method picker, the `paymentMethods` const, the
  card badge, `payment_method` from both request bodies, and the 3 orphaned
  translation keys.
- `[id].vue` — removed the header badge and the `canDelete` filter so the delete
  button shows on every entry, matching the relaxed API.

Both SFCs compile clean via `@vue/compiler-sfc`; `vue-tsc` reports no war-chest errors.

### Behavioral tests run (local Docker)
| Test | Result |
|---|---|
| Admin sets balance via `PUT` → 5000 | balance 5000, `adjustment` row written |
| Create expense, `payment_method: Stripe`, $777 | balance **unchanged**, 0 new ledger rows |
| Delete that expense | balance **unchanged** |
| Order `TST0045B4F` → `PAID`, `paid_location: Stripe` | balance **unchanged**, 0 new ledger rows, `paid_location` still saved |
| Manual entry +1200 then −200 | 6200 → 6000 |
| Delete a legacy `order`-sourced entry | HTTP **200** (was 422), balance correctly reversed |
| Ledger sum vs `current_balance` | reconciles exactly |

Test data was cleaned up afterwards (Stripe US reset to 0, ledger cleared). The local
order flipped to PAID was left as-is — local DB only, no production impact.

### Not done (by your call)
- No Stripe API integration of any kind: no balance sync, no webhook crediting, no fee
  or payout handling. `StripeWebhookController` was already inert w.r.t. the War Chest
  and still is.
- `Order::PAID_LOCATIONS` and `BusinessExpense::PAYMENT_METHODS` remain
  `['NU','HSBC','Stripe']` — so orders/expenses still record a single undifferentiated
  `Stripe`. Split those separately if you want US/MX granularity in expense reporting.
- `cli/` still has no war-chest commands.
