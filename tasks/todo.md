# Task: Add Accounts Receivable to Unified Dashboard

## Problem
Need to track outstanding payments (accounts receivable) in the admin dashboard. These are orders where:
- Order has a box selected (so there's a price)
- Final payment (`paid_at`) has NOT been made yet
- Order is not cancelled

This represents money owed to the business that should be displayed alongside profits and expenses.

## Solution
Add an `accounts_receivable` field to the financial data in `UnifiedAdminDashboardController`. This is a simple addition to the existing `getFinancialData()` method.

## Todo
- [x] Add accounts receivable calculation to `getFinancialData()` method
- [x] Include AR in both period-specific and "all time" modes
- [x] Return breakdown: total AR amount and count of orders
- [x] Add Accounts Receivable card to frontend dashboard
- [x] Add translations for Accounts Receivable

## Files Changed

### Backend (boxly-api)
- `app/Http/Controllers/UnifiedAdminDashboardController.php`

### Frontend (app)
- `pages/app/admin/dashboard/index.vue`

## Review

### Backend Changes

**UnifiedAdminDashboardController.php** - Added accounts receivable tracking:

1. Added `calculateAccountsReceivable()` helper method (lines 964-998):
   - Queries orders where `paid_at` is NULL and status is not cancelled
   - Filters to orders that have boxes (either in `order_boxes` table or legacy `box_price` field)
   - Uses the existing `calculateTotalBoxPrice()` method from Order model
   - Returns `total` (amount) and `count` (number of orders)

2. Added AR calculation call in financial data section (line 473)

3. Added `accounts_receivable` to both "all time" and period-specific return blocks

### Frontend Changes

**pages/app/admin/dashboard/index.vue**:

1. Changed financial overview grid from 3 to 4 columns
2. Added new Accounts Receivable card with:
   - Orange gradient styling (consistent with existing card design)
   - Clock icon to represent pending payments
   - Total amount display
   - Count of unpaid orders
3. Added translations:
   - `accountsReceivable`: "Accounts Receivable" / "Cuentas por Cobrar"
   - `unpaidOrders`: "unpaid orders" / "órdenes pendientes"

### API Response Format
```json
{
  "financial": {
    "accounts_receivable": {
      "total": 12500.00,
      "count": 15
    }
  }
}
```

### How It Works
- Orders with boxes that haven't been fully paid represent money owed
- **Subtracts any deposits already paid** to show true outstanding amount
- Formula: `box_price - deposit_paid = accounts_receivable`
- Displayed as a 4th card in the financial overview section
- Shows both the total amount and the number of orders pending payment

---

# Feature: Simplified Package Arrival + Proof Image (API)

## Overview
Backend changes to support simplified arrival flow with proof image:
1. Add arrival image fields to orders table
2. Create endpoint to upload arrival image
3. Update packages_complete trigger to require arrival image
4. Send email with arrival image to customer

---

## Tasks

### Phase 1: Database & Model
- [x] 1. Create migration to add arrival image fields to orders table
- [x] 2. Update Order model with arrival image methods

### Phase 2: Controller & Endpoint
- [x] 3. Add `uploadArrivalImage` method to AdminOrderController
- [x] 4. Add route for the new endpoint: `POST /admin/orders/{order}/arrival-image`

### Phase 3: Email Notification
- [x] 5. Create new email template `all-packages-arrived.blade.php`
- [x] 6. Create Mailable class `AllPackagesArrived`

### Phase 4: Cleanup
- [x] 7. Update `checkAndUpdatePackageStatus()` to NOT auto-trigger packages_complete
- [x] 8. Update `markAsComplete()` to NOT auto-trigger packages_complete

---

## Review

### Files Created

**`database/migrations/2026_01_13_071425_add_arrival_image_to_orders_table.php`**
- Adds 5 new columns to orders table:
  - `arrival_image_path`, `arrival_image_filename`, `arrival_image_mime_type`
  - `arrival_image_size`, `arrival_image_url`

**`app/Mail/AllPackagesArrived.php`**
- New Mailable class for arrival notification email
- Includes order, user, items list, and arrival image URL
- Multi-language support (ES/EN)

**`resources/views/emails/orders/all-packages-arrived.blade.php`**
- Email template showing:
  - Arrival image
  - List of all items received
  - "What's next" section explaining the process
  - CTA to view order

### Files Modified

**`app/Models/Order.php`**
- Added fillable fields for arrival image
- Added cast for `arrival_image_size`
- Added `hasArrivalImage()` method
- Added `getArrivalImageFullUrlAttribute()` accessor
- Added `deleteArrivalImage()` method
- Modified `checkAndUpdatePackageStatus()` - no longer auto-triggers packages_complete
- Modified `markAsComplete()` - no longer auto-triggers packages_complete

**`app/Http/Controllers/AdminOrderController.php`**
- Added `uploadArrivalImage()` method:
  - Validates image file (jpeg, jpg, png, webp, max 10MB)
  - Checks all items are arrived
  - Stores image in DigitalOcean Spaces
  - Updates order status to `packages_complete`
  - Queues `AllPackagesArrived` email to customer

**`routes/api.php`**
- Added route: `POST /admin/orders/{order}/arrival-image`

### Logic Change

Previously: `packages_complete` status was auto-triggered when all items were marked as arrived.

Now: `packages_complete` status is ONLY triggered when admin uploads the arrival proof image. This ensures:
1. Admin has physically verified all items
2. Customer receives a photo proof
3. Better accountability and communication

---

# Feature: Order Consolidation (API)

## Overview
Allow admins to consolidate multiple orders from the same user into a single order. This fixes the issue where users create multiple orders instead of adding all items to one order.

## Requirements
- Orders must belong to the same user (user_id) to be consolidated
- All items from source orders get merged into target order
- Source orders are deleted after consolidation
- Order with furthest status becomes target (delivered > shipped > paid > awaiting_packages)

## Status Priority (furthest wins)
1. `delivered` (highest)
2. `shipped`
3. `paid`
4. `processing`
5. `awaiting_payment`
6. `packages_complete`
7. `awaiting_packages` (lowest)

---

## Tasks

- [ ] 1. Create `consolidateOrders` method in AdminOrderController
  - Accepts: `order_ids[]` (array of order IDs to consolidate)
  - Validates all orders belong to same user
  - Auto-selects target order based on furthest status
  - Moves all items from source orders to target order
  - Deletes source orders
  - Returns updated target order with items

- [x] 2. Add route: `POST /admin/orders/merge`

---

## Review

### Files Modified

**`routes/api.php`**
- Added route: `POST /admin/orders/merge` → `AdminOrderController@mergeOrders`

**`app/Http/Controllers/AdminOrderController.php`**
- Added `mergeOrders()` method with:
  - Validation: requires 2+ order IDs, all must belong to same user
  - Status priority logic to auto-select target order (furthest status wins)
  - Moves all items from source orders to target order
  - Deletes source orders (including boxes, GIA files, arrival images)
  - Returns updated target order with success message

### Status Priority (furthest wins)
1. `delivered` (highest)
2. `shipped`
3. `paid`
4. `processing`
5. `awaiting_payment`
6. `packages_complete`
7. `awaiting_packages`
8. `collecting` (lowest)
9. `cancelled` (-1, never selected)

### API Response
```json
{
  "success": true,
  "message": "Successfully merged 3 orders. Moved 5 items.",
  "data": { /* target order with items and boxes */ }
}
```
