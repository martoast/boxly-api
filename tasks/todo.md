# Mark All Order Items as Arrived

## Task
Add an endpoint to allow admin users to mark ALL order items as arrived in a single request.

## Plan
- [x] Add `markAllArrived` method to `AdminOrderItemController`
- [x] Add route `PUT /admin/orders/{order}/items/mark-all-arrived` to routes/api.php
- [x] Verify syntax

## Review
Added new endpoint that:
- Checks order isn't in 'collecting' status
- Marks all pending items as arrived using existing `markAsArrived()` method
- Updates order's total weight
- Returns count of items marked and updated order data

**Endpoint:** `PUT /admin/orders/{order}/items/mark-all-arrived`

No weight/dimensions required - admin can update those individually later if needed.
