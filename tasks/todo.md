# Task: Simplify Admin Purchase Request Update Endpoint

## Problem
Admin currently has to manually enter `shipping_cost` and `processing_fee` when updating a purchase request. This is annoying and unnecessary.

## Solution
- Only require `items_total` and `total_amount`
- Calculate `processing_fee` automatically as: `total_amount - items_total`
- Remove `shipping_cost` and `processing_fee` from required fields

## Todo
- [x] Update `AdminPurchaseRequestController::update()` method to calculate `processing_fee` automatically

## Files to Change
- `app/Http/Controllers/AdminPurchaseRequestController.php` - the `update` method only

## Review

### Change Made
Added 4 lines of code to auto-calculate `processing_fee` when both `items_total` and `total_amount` are provided:

```php
if (isset($validated['items_total']) && isset($validated['total_amount'])) {
    $validated['processing_fee'] = $validated['total_amount'] - $validated['items_total'];
}
```

### How It Works Now
- Admin sends: `items_total` (cost of goods) and `total_amount` (what customer pays)
- System calculates: `processing_fee = total_amount - items_total` (your profit)
- `shipping_cost` and `processing_fee` fields still accepted if admin wants to override manually
