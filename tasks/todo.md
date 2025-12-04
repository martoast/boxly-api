# Task: 1 GIA File Per Box (Multi-Box GIA Support)

## Problem
Currently, an order can only have **1 GIA file** stored at the Order level. However, in reality, **each physical box** shipped requires its own GIA file. If an order has 2 Extra Large boxes, it needs 2 separate GIA files.

## Current State

### Order Model (GIA fields):
```php
'gia_path',        // Storage path
'gia_filename',    // Original filename
'gia_mime_type',   // MIME type
'gia_size',        // File size
'gia_url',         // Full URL
'guia_number',     // DHL tracking number (single)
```

### OrderBox Model (NO GIA fields):
```php
'order_id',
'stripe_price_id',
'stripe_product_id',
'box_size',
'box_name',
'box_price',
'currency',
'quantity',  // Can be > 1 (e.g., "2x Extra Large")
```

### The Problem:
- OrderBox can have `quantity: 2` meaning "2 extra large boxes"
- But each physical box has its own GIA file
- Currently only 1 GIA can be uploaded per order

---

## Proposed Solution

### Design Decision: Expand Boxes to Individual Entries

When admin ships an order:
1. If a box has `quantity > 1`, expand it into multiple individual OrderBox entries with `quantity = 1`
2. Each individual OrderBox gets its own GIA file and guia number
3. This creates a 1:1 relationship between physical boxes and GIA files

**Example:**
- Before shipping: 1 OrderBox entry with `quantity: 2, box_name: "Extra Large"`
- After shipping: 2 OrderBox entries, each with `quantity: 1`, each with its own GIA

### Why This Approach:
- Cleaner data model (each row = 1 physical box)
- Simple file handling (each box has its own fields)
- Easier to track and manage individual shipments
- Better for reporting and auditing

---

## Implementation Plan

### Step 1: Database Migration
**Create migration to add GIA fields to order_boxes table:**

```php
Schema::table('order_boxes', function (Blueprint $table) {
    $table->string('guia_number', 50)->nullable()->after('quantity');
    $table->string('gia_path', 1000)->nullable();
    $table->string('gia_filename', 255)->nullable();
    $table->string('gia_mime_type', 50)->nullable();
    $table->unsignedInteger('gia_size')->nullable();
    $table->string('gia_url', 1000)->nullable();
});
```

### Step 2: Update OrderBox Model
**Add new fields and methods:**

```php
// Add to fillable
'guia_number', 'gia_path', 'gia_filename', 'gia_mime_type', 'gia_size', 'gia_url'

// Add accessor for full URL
public function getGiaFullUrlAttribute(): ?string

// Add method to delete GIA file
public function deleteGia(): void
```

### Step 3: Update AdminShipOrderRequest
**Modify validation to support per-box GIA files:**

For shipping orders:
- `boxes.*.guia_number` - required string for each box
- `boxes.*.gia_file` - required PDF file for each box
- Total GIA files must equal total box quantity

### Step 4: Update AdminOrderController::shipOrder()
**Modify shipping logic:**

1. Expand boxes with `quantity > 1` into individual entries
2. Accept array of GIA files matched to each physical box
3. Upload each GIA to box-specific storage path
4. Save GIA metadata to each OrderBox entry
5. Keep Order-level fields for backwards compatibility (store first box's GIA)

**New storage structure:**
```
users/{user}/orders/{order}/boxes/{box_id}/gia-{timestamp}.pdf
```

### Step 5: Update Email Templates
**OrderShippedWithDeposit.php:**
- Attach multiple GIA files (one per box) OR
- Include download links for each GIA in email body

**shipped-with-deposit.blade.php:**
- Show each box with its guia number
- Provide individual download links for each GIA file

### Step 6: Update Order Model
**Modify GIA-related methods:**
- Keep legacy fields for backwards compatibility
- Add helper methods to get all box GIAs
- Update `deleteGia()` to handle box-level files

---

## Files to Modify

1. **New Migration**: `add_gia_fields_to_order_boxes_table.php`
2. **app/Models/OrderBox.php** - Add GIA fields and methods
3. **app/Models/Order.php** - Update GIA helper methods
4. **app/Http/Requests/AdminShipOrderRequest.php** - Update validation
5. **app/Http/Controllers/AdminOrderController.php** - Update shipOrder logic
6. **app/Mail/OrderShippedWithDeposit.php** - Handle multiple attachments
7. **resources/views/emails/orders/shipped-with-deposit.blade.php** - Show per-box GIAs

---

## API Changes

### Ship Order Request (Updated)
```json
{
  "boxes": [
    {
      "stripe_price_id": "price_123",
      "quantity": 1,
      "guia_number": "1234 5678 9012",
      "gia_file": <File>
    },
    {
      "stripe_price_id": "price_123",
      "quantity": 1,
      "guia_number": "1234 5678 9013",
      "gia_file": <File>
    }
  ],
  "estimated_delivery_date": "2024-12-15"
}
```

**Note:** Frontend should expand quantity > 1 into individual box entries before submitting.

### Response (Updated)
Each box in the response will include:
```json
{
  "id": 1,
  "box_name": "Extra Large Box",
  "box_price": 45.00,
  "quantity": 1,
  "guia_number": "1234 5678 9012",
  "gia_url": "https://..../gia.pdf"
}
```

---

## Backwards Compatibility

1. **Order-level GIA fields preserved**: Store first box's GIA in order fields for legacy code
2. **Existing emails work**: Template updates show all boxes, falls back gracefully
3. **Single-box orders unchanged**: Work exactly as before
4. **Frontend flexibility**: Can send expanded boxes or let backend expand

---

## Email Changes Detail

### Current email shows:
- Single guia number
- Single GIA file attachment
- Box summary table

### Updated email will show:
- Table with each box and its guia number
- Multiple GIA file attachments OR download links
- Individual tracking links per box if different carriers

---

## Testing Checklist

- [ ] Ship order with 1 box → 1 GIA file
- [ ] Ship order with 2 same boxes → 2 GIA files
- [ ] Ship order with mixed boxes (1 small, 2 large) → 3 GIA files
- [ ] Email received with correct number of GIA attachments
- [ ] Each box displays correct guia number in email
- [ ] Download links work for each GIA
- [ ] Crossing orders still work (no GIA required)
- [ ] Legacy single-box code still works
