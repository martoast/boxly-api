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
