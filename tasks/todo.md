# Store Checkout → PR Review Flow Migration

Convert the Boxly Store checkout from "pay-up-front via Stripe" to a two-step flow that mirrors the existing assisted-PR pipeline: customer creates request → Velonie verifies stock per-item → Velonie sends Stripe invoice → customer pays → Velonie buys.

## Decisions confirmed with Alex
- **Markup**: store products already have markup baked in → `processing_fee = 0` for `source=store` PRs
- **Unavailable items**: keep them on the PR (so customer sees them) but exclude from the Stripe invoice. Customer only pays for available items.
- **Auth**: `/checkout` page already has `middleware: ['auth']` — verified, no change needed there.

## Backend (api/) — todo

- [ ] **Migration** — `add_stock_status_to_purchase_request_items.php`
  - `stock_status` enum: `unverified` (default), `available`, `unavailable`
  - `stock_checked_at` nullable timestamp
  - `stock_checked_by` nullable user_id (audit trail — who marked it)
- [ ] **`PurchaseRequestItem` model** — add `stock_status`, `stock_checked_at`, `stock_checked_by` to `$fillable`; add status constants
- [ ] **`PurchaseRequest` model** — add helper `isStore()` returning `source === SOURCE_STORE`; helper `allItemsStockChecked()` for validation gate; helper `availableItems()` returning items where `stock_status='available'`
- [ ] **Rewrite `StoreCheckoutController::create`**:
  - Create PR in `STATUS_PENDING_REVIEW` (was `STATUS_QUOTED`)
  - Items default to `stock_status='unverified'`
  - **No Stripe session creation** — return PR ID + redirect URL `/app/purchase-requests/{id}`
  - Pre-fill `items_total` and `total_amount` with the customer-facing MXN price (markup baked in)
  - Send `PurchaseRequestStoreReceived` email to customer
  - Send `PurchaseRequestCreatedTeamNotification` to shopping team (already exists for assisted PRs — reuse)
- [ ] **New endpoint** — `PUT /admin/purchase-requests/{prId}/items/{itemId}/stock-status`
  - Body: `{ stock_status: "available" | "unavailable" }`
  - Updates item, sets `stock_checked_at = now()`, `stock_checked_by = auth user id`
  - Refuse if PR is already in `quoted/paid/purchased` (item state is locked once quoted)
- [ ] **Modify `AdminPurchaseRequestController::createQuote`**:
  - Branch on `$purchaseRequest->source`:
    - **Store PR**: skip the manual `items_total/shipping/sales_tax` input. Auto-compute from `availableItems()` (sum of price × quantity, MXN). `processing_fee = 0`. Currency stays `mxn`. Stripe invoice has ONE line item per available product.
    - **Assisted PR**: keep current logic untouched (USD inputs + 8% markup + USD→MXN conversion).
  - Validation gate: refuse to quote if any item is still `unverified` (only applies to store PRs).
- [ ] **New email** — `app/Mail/PurchaseRequestStoreReceived.php` (customer): "Recibimos tu solicitud, Velonie está verificando stock"
- [ ] **Routes** — register the new stock-status endpoint inside the existing `admin/purchase-requests/{id}` group
- [ ] **Webhook** — verify `StripeWebhookController` handles `invoice.paid` for `type=purchase_request_invoice` correctly for store PRs (should — same field shape). No code change expected.

## Frontend admin (app/) — todo

- [ ] **`pages/app/admin/purchase-requests/[id]/index.vue`** — store-source detail view enhancements:
  - Per-item: ✓ / ✗ buttons (or "Available" / "Unavailable" pills). Disabled once PR is `quoted` or beyond.
  - Counter at top: "X de Y verificados"
  - "Crear cotización" button: disabled until all items checked
  - For store PRs, the quote modal should not ask for items_total/shipping/tax — just confirm "create Stripe invoice for $XXX (only available items)"
  - For assisted PRs: keep current behavior unchanged
- [ ] **`pages/app/admin/purchase-requests/index.vue`** — add `source` filter chip (Store / Asistido / All)

## Frontend storefront (app/) — todo

- [ ] **`pages/checkout.vue`**:
  - Change CTA from "Pagar productos" → "Crear solicitud"
  - Update `shippingNote` and `total` labels to reflect new flow
  - On submit success, `clear()` cart and route to `/app/purchase-requests/{id}` with a banner "Solicitud creada — Velonie verificará stock pronto"
  - Remove `window.location.href = res.checkout_url` (no Stripe redirect)
- [ ] **`pages/app/purchase-requests/[id]/index.vue`** (customer view) — verify customers can see their store PRs and their item-level stock_status badges

## Out of scope (deferred)
- Per-variant images on the storefront (separate ticket — needs DB migration to `product_variants.image_url`)
- Bulk "mark all available" action (can add if Velonie asks)

## Sequence
1. Backend migration + models (atomic, ~15 min)
2. Backend `StoreCheckoutController` rewrite + email (~20 min)
3. Backend new stock-status endpoint + route (~10 min)
4. Backend `createQuote` branching (~20 min)
5. Frontend storefront `checkout.vue` (~15 min)
6. Frontend admin PR detail stock-check UI (~30 min)
7. Frontend admin PR list source filter (~10 min)
8. Manual smoke test: panty checkout → Velonie verifies → quote → pay → mark purchased

## Review

### What changed

**Backend (api/):**
- ✅ Migration `2026_05_03_000000_add_stock_status_to_purchase_request_items.php` adds `stock_status` enum (default `unverified`), `stock_checked_at`, `stock_checked_by` (FK → users)
- ✅ `PurchaseRequestItem`: new `STOCK_*` constants + fillable/cast updates
- ✅ `PurchaseRequest`: new helpers `isStore()`, `allItemsStockChecked()`, `availableItems()`
- ✅ `StoreCheckoutController` rewritten — creates PR in `pending_review` (was `quoted`), no Stripe session, sends `PurchaseRequestCreated` to customer + `PurchaseRequestCreatedTeamNotification` to shopping team. Returns redirect URL to the customer's PR detail page.
- ✅ `AdminPurchaseRequestController::createQuote` branches on source — store PRs route to new private `createStoreQuote` method that:
  - Refuses if any item still `unverified`
  - Refuses if no items are available
  - Sums available items only (already MXN with markup baked in)
  - Creates Stripe invoice with one line per available item
  - Sets `processing_fee = 0`, `total_amount = sum of available`, status → `quoted`
- ✅ `AdminPurchaseRequestController::updateItemStockStatus` — new endpoint `PUT .../items/{id}/stock-status` for per-item ✓/✗ marking
- ✅ `AdminPurchaseRequestController::index` — added `source` query param filter
- ✅ `processPurchase` — for store PRs, only `availableItems()` get copied to the Order (unavailable ones stay on the PR for visibility)
- ✅ Routes registered under both `admin/purchase-requests` and `shopping/purchase-requests`

**Frontend (app/):**
- ✅ `pages/checkout.vue` — CTA changed to "Crear solicitud", removed Stripe redirect, navigates to PR detail page after submit
- ✅ `pages/app/admin/purchase-requests/[id]/index.vue` — per-item ✓/✗ buttons, stock-check progress banner, source-aware Create Quote button (disabled until all items checked, store flow uses confirm dialog instead of modal)
- ✅ `pages/app/shopping/purchase-requests/[id]/index.vue` — same as admin (Velonie's view)
- ✅ `pages/app/admin/purchase-requests/index.vue` + `pages/app/shopping/purchase-requests/index.vue` — added Source filter dropdown
- ✅ `pages/app/purchase-requests/[id]/index.vue` — customer view shows stock_status badges per item

### Manual steps remaining (no code change can do these for you)
1. Run the migration: `cd api && php artisan migrate`
2. Smoke test: capture a product → checkout → see PR in `pending_review` (no Stripe redirect) → as Velonie, mark items ✓/✗ → click Create Quote → customer gets Stripe invoice → pays → flow to Mark Purchased → Order created with only available items

### Behavior matrix

| Source | Status | What Velonie sees |
|---|---|---|
| store | pending_review | ✓/✗ buttons per item, "Crear cotización" disabled until all checked, then confirm dialog ("$XXX MXN, only available items") |
| store | quoted/paid/purchased | Stock status badge per item (locked, can't change) |
| assisted | pending_review | Existing modal asking for items_total / shipping / sales_tax in USD with 8% markup applied |
| assisted | any | No stock-check UI (stock_status stays `unverified` and is ignored by source-aware logic) |

### Things deliberately NOT changed (preserves existing behavior)
- Assisted-PR quote flow (USD inputs, 8% markup, USD→MXN conversion)
- Stripe webhook (`invoice.paid` already flips both source types from quoted → paid identically)
- Order creation downstream (same flow once `processPurchase` runs)
- Manual deposit (NU bank transfer) flow on assisted PRs

### Risks / things to watch on first prod run
- Old store PRs (created before this migration) have `stock_status` defaulting to `unverified`. None of them are in `pending_review` though, so the gate logic doesn't fire. Only NEW store PRs go through the new flow.
- The cycler-built panty/bra products have huge variant counts. The current `variants/sync` POST is not chunked — first ~1700-variant submit might fail with payload-too-large. If that happens, we add chunking on a follow-up.

# Customer-visible caveat label (partial_match) — API side (PLAN, awaiting approval)

Status: PLAN ONLY — nothing below is implemented until approved. Never push (a push deploys).

Facts (live trace 2026-09-03, sessions ace8d110 / 80cb048b): the engine terminal and the
`live_shopping_sessions` row both carry `error_code = partial_match` for a COMPLETED session, and the
webhook-persisted `tool-live_results` part carries `output.caveat = partial_match`. The customer never
sees it because:
1. `app/Http/Controllers/LiveShoppingController.php:351-361` `publicErrorCode()` returns null unless
   `status === failed`, so `GET /live-shopping/sessions/{id}` presents `error_code: null` for a completed
   session — and the Nuxt panel's authority reads commit their terminal from exactly this answer.
2. The status-reconcile projector (`LiveShoppingController.php:277-280`) builds
   `assistant_part.output = ['products' => …]` WITHOUT the caveat, while the webhook projector
   (`LiveShoppingWebhookController.php:290-294`) adds `caveat` when `result.error_code` is partial_match.
   Whichever projector wins the race decides whether the persisted gallery part carries the caveat.

## Todo (smallest change)
- [x] `publicErrorCode()`: closed vocabulary per status — `failed` unchanged (slug or literal 'failed');
      `completed` exposes exactly `'partial_match'` when that is stored, otherwise null; every other status
      null. Update the docblock ("Only a FAILED session…" → failed reasons + the one completed caveat).
- [x] Status-reconcile projector: `'output' => array_merge(['products' => $products],
      $remote['error_code'] === 'partial_match' ? ['caveat' => 'partial_match'] : [])` — the exact
      webhook shape. Verify `ProcessLiveShoppingResultJob` persists the receipt's `assistant_part`
      verbatim (it does not re-derive the output) so both projectors persist the same part.
- [x] Tests — `tests/Feature/LiveShopping/PublicContractTest.php::test_error_code_is_sanitized_failed_only_and_null_otherwise`
      (rename to `…_failed_reasons_and_the_completed_caveat_only`): completed + `store_blocked` → null
      (kept); completed + `partial_match` → `partial_match`; cancelled + `partial_match` → null;
      completed + `PARTIAL_MATCH` / `partial_match!` → null; failed cases unchanged.
      `tests/Feature/LiveShopping/ShowSessionTest.php`: a status read whose engine terminal is
      `completed` + `error_code partial_match` persists the part with `output.caveat = partial_match`
      and the row with `error_code = partial_match`; with `error_code null` the part has no `caveat` key
      (existing case).
- [x] Run `php artisan test --filter LiveShopping` (needs the docker MySQL test database).
- [x] Review section below; hand the diff to the lead. NO PUSH.

## Review (2026-09-03)
- Change: `LiveShoppingController::publicErrorCode()` now has a closed vocabulary per status — `failed`
  unchanged (sanitized slug or the literal `failed`), `completed` exposes exactly `partial_match` when that
  is what the engine stored (`COMPLETED_CAVEAT`), every other status and every other completed value is
  null. The status-reconcile projector adds `output.caveat = 'partial_match'` to the persisted assistant part
  with the exact shape the webhook projector already used, so both projectors persist the same part and the
  gallery can render the "partial match" label whichever one wins the race. No other endpoint, job or
  schema changed; no migration.
- Tests: `PublicContractTest::test_error_code_is_sanitized_failed_reasons_and_the_completed_caveat_only`
  (completed + store_blocked → null kept; completed + partial_match → partial_match; cancelled + partial_match
  → null; `PARTIAL_MATCH` / `partial_match!` → null; failed cases unchanged) and
  `ShowSessionTest::test_a_completed_partial_match_status_read_persists_the_caveat_on_the_part`.
- Run (docker `boxly-local-stack-laravel.test-1`, `APP_ENV=testing LIVE_SHOPPING_MYSQL_GATE=1
  DB_DATABASE=testing DB_HOST=mysql`, stdout to a file): the two changed files → 12 passed (59 assertions),
  773.87 s. A full `--filter LiveShopping` run was started twice: the first, piped, was stopped after ~100
  min with 12 classes PASS (through `ReconcileTest`, `PublicContractTest` included) and no summary line; the
  second was started to a file and is left running in the background.
- Cost note for whoever runs this next: every LiveShopping test re-migrates the `testing` schema from
  scratch (`RefreshDatabase` + the live-shopping MySQL gate), ~60–70 s per test on this stack — the full
  filter takes hours. Worth a `DatabaseTransactions`/migrate-once fixture before the suite grows; not
  changed here (out of scope for the caveat label).
- Not pushed. Diff handed to the lead as `/tmp/boxly-api-caveat-label.patch`.
