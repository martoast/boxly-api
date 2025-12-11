# Dashboard: Switch from `created_at` to `paid_at` for Order Attribution

## Context
Since orders now have a single payment (100% at consolidation) instead of deposit + final payment, we need to attribute orders to months based on when they were **paid**, not when they were created.

This affects:
- Revenue calculations (already using `paid_at` - correct)
- Order counts in financial metrics
- Box distribution counts

## Changes Required

### 1. `getFinancialData()` - Order Counts
- [ ] Line 561: Change `Order::whereBetween('created_at', ...)` to `paid_at`
- [ ] Line 648: Change `total_orders_calculated` query from `created_at` to `paid_at`

### 2. `getBoxDistribution()` - Box Counts by Month
- [ ] Line 750-752: Change OrderBox query to use order's `paid_at`
- [ ] Line 754-757: Change not_selected Order query to use `paid_at`
- [ ] Update `getBoxCountsFromOrderBoxes()` helper to use `paid_at`
- [ ] Update `getLegacyBoxCounts()` helper to use `paid_at`

### 3. `getOrdersData()` - Orders by Status
This is for **operational tracking** (collecting, awaiting_packages, etc.) - orders might not have `paid_at` yet.
**Decision**: Keep using `created_at` since it's for operational visibility of orders in various stages.

### 4. `getOverview()` - Overview Metrics
- [ ] Line 307: `total_orders` should use `paid_at` for consistency with financial
- [ ] Line 308-315: `active_orders` - keep `created_at` (operational metric)

## Notes
- Only PAID orders have a `paid_at` date
- Revenue is already correctly using `paid_at`
- This change aligns order counts and box distribution with revenue attribution

## Review

### Changes Made
All changes in `UnifiedAdminDashboardController.php`:

1. **`getOverview()`** - Line 307
   - `total_orders` now uses `whereNotNull('paid_at')->whereBetween('paid_at', ...)`
   - `active_orders` kept using `created_at` (operational metric)

2. **`getFinancialData()`** - Lines 561, 648
   - `ordersToUse` now uses `paid_at` instead of `created_at`
   - `total_orders_calculated` now uses `paid_at` instead of `created_at`

3. **`getBoxDistribution()`** - Lines 750-758
   - OrderBox query now filters by order's `paid_at`
   - `notSelected` query now filters by `paid_at`

4. **`getBoxCountsFromOrderBoxes()`** - Lines 804-807
   - Now uses order's `paid_at` instead of `created_at`

5. **`getLegacyBoxCounts()`** - Lines 836-838
   - Now uses `paid_at` instead of `created_at`

### What This Means
- Orders are now attributed to months based on when they were **paid**, not created
- Box distribution follows the same logic - boxes count toward the month payment was received
- Revenue and order counts are now aligned
- Operational metrics (active_orders, orders by status) still use `created_at`
