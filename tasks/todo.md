# Order Weight from Boxes Sum

## Problem
The order's weight should default to the sum of weights from all boxes in the order, while still allowing admin to manually override.

## Proposed Solution
Add a method to calculate total box weight and use it as default when displaying order weight.

---

## Todo Items

- [x] Add `calculateTotalBoxWeight()` method to Order model
- [x] Add `total_box_weight` appended attribute for API responses

---

## Review

### Changes Made:
1. **Order Model**: Added `calculateTotalBoxWeight()` method - sums weight from all boxes
2. **Order Model**: Added `getTotalBoxWeightAttribute()` accessor
3. **Order Model**: Added `$appends` array with `total_box_weight` so it's included in all API responses

### Files Modified:
- `app/Models/Order.php`

### How It Works:
- `total_box_weight` is automatically calculated from the sum of all box weights
- Returned in all order API responses
- Admin can still manually set `total_weight` or `actual_weight` fields if they need to override
