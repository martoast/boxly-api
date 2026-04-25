# Boxly Store — API Implementation Plan

## Vision

Add the **Boxly Store** — a regular e-commerce storefront inside Boxly where customers browse and buy products that Boxly stocks. Boxly is the only seller. Admin team uploads and manages all products via the existing admin panel.

**The Boxly twist on standard e-commerce:** customers can buy a little now and add more later. Each purchase is added to their **open marketplace order** (the same model as forwarding orders — items accumulate at our San Diego warehouse until the customer is ready to ship). When they decide to ship, Boxly consolidates everything into one box and invoices the shipping. Bigger box, cheaper per-kg.

This is *not* a wholesale marketplace, *not* a B2B platform — it's a regular online store with one elegant difference: orders accumulate, then ship as a consolidated box.

**Two parties:**
1. **Boxly admin** — uploads and manages products
2. **Customers** — browse, add to cart, pay for products, accumulate items, request shipment when ready

**No minimum order to buy.** Customers can purchase anything any time. The shipping cost (which box) is only determined when they request shipment, after Boxly consolidates whatever they've accumulated. If they only have a tiny bit, it ships in an XS box. If they keep buying, it consolidates into an L or XL.

Marketplace orders flow through the same warehouse pipeline as forwarding orders (receive → accumulate → pack → assign box → invoice shipping → DHL).

---

## Architectural Decision

**New models — do NOT extend the existing `Order` model.**

The existing `Order` model is tightly coupled to package forwarding (customer-supplied tracking, GIA per-box, arrival images, retailer scraping). Mixing marketplace concerns into it would create weeks of conditional branching.

**New models for the marketplace:**
- `Product` — catalog item managed by Boxly admin
- `MarketplaceOrder` — purchase record (what the buyer made)
- `MarketplaceOrderItem` — line items

The fulfillment layer (admin packing UI, GIA upload, DHL workflow) is reused at the **operational** level, not the data-model level. Admin gets a unified packing queue that includes both forwarding orders and marketplace orders.

---

## What's already in place we'll reuse

| Existing infrastructure | Reuse for |
|---|---|
| Laravel Cashier + Stripe webhooks | Marketplace checkout payment + shipping invoice |
| `StripeWebhookController` metadata routing | Add `marketplace_purchase` + `marketplace_shipping` types |
| DigitalOcean Spaces upload pattern | Product images |
| Order status state-machine UX | Marketplace order timeline (similar lifecycle) |
| Existing `admin` middleware | Product CRUD + marketplace order management |
| Existing image compression on employee upload page | Product image uploads (admin on slow connections) |

---

## Data Model

### Phase 1

#### `products` table
```
id
name
slug                unique
description         text
sku                 string nullable (Boxly internal SKU)
source_url          string nullable (admin-only — link to where Boxly buys this; will eventually power auto-data-extraction)

# Pricing
price_cents         int  (MXN, set by admin)

# Physical — used to estimate which box the order will need
weight_kg           decimal(6,2)  REQUIRED
length_cm           decimal(6,1)  REQUIRED
width_cm            decimal(6,1)  REQUIRED
height_cm           decimal(6,1)  REQUIRED

stock               int  default 0
status              enum: draft, active, inactive, sold_out
available_until     timestamp nullable  (7-day clearance window)
category            string nullable
images              json (array of {path, url, order})

created_at, updated_at
```

**Notes:**
- `source_url` is admin-only — never exposed to customers. Future use: auto-fetch product data when admin pastes the URL.
- Weight/dimensions drive cart's box estimate.

#### `marketplace_orders` table

A marketplace_order represents **a shipment-in-progress**. A customer has at most one in `collecting` state at a time. Each Stripe Checkout adds items to that open order. When the customer requests shipment, the order moves through the lifecycle.

```
id
order_number              unique, auto-generated (e.g. "MKT-A4F92")
user_id                   FK users.id

status                    enum:
                            collecting          → items being added; customer can keep buying
                            ready_to_ship       → customer has requested shipment; admin sees in queue
                            packing             → admin is packing; some items received, some pending
                            awaiting_shipping_payment → admin assigned box, invoice sent
                            shipping_paid       → customer paid shipping
                            shipped             → out the door
                            delivered           → received in MX
                            (cancelled, refunded — at any earlier stage)

# Cumulative items payment — sum of all Checkouts that contributed
items_subtotal_cents      int (MXN, grows as customer adds purchases)
items_paid_at             timestamp nullable (set when first purchase paid)

# Box assignment (post-consolidation, by admin)
box_size                  enum: XS, S, M, L, XL  nullable
box_price_cents           int nullable
box_summary               json nullable (admin's packing notes)

# Shipping payment (after consolidation)
shipping_invoice_id       string nullable (Stripe)
shipping_payment_link     string nullable
shipping_paid_at          timestamp nullable

# Delivery
shipping_address          json (snapshot at checkout)
guia_number               string nullable
gia_path / gia_url        string nullable
estimated_delivery_date   date nullable
actual_delivery_date      date nullable
shipped_at                timestamp nullable

# Refund
refunded_at               timestamp nullable
refund_amount_cents       int nullable
refund_reason             text nullable

created_at, updated_at
```

#### `marketplace_order_items` table

Each item belongs to a marketplace_order. Items are added as the customer makes purchases. Each item tracks which Stripe Checkout paid for it (so refunds work cleanly per-purchase).

```
id
marketplace_order_id      FK
product_id                FK

# Snapshots at purchase time (so historical orders are stable)
name_snapshot             string
price_cents_snapshot      int
weight_kg_snapshot        decimal(6,2)
image_url_snapshot        string nullable

quantity                  int

# Stripe payment tracking — which Checkout session paid for this item
stripe_checkout_session_id   string
stripe_payment_intent_id     string

# Per-item fulfillment
status                    enum: ordered, received, packed, shipped
received_at               timestamp nullable

created_at, updated_at
```

---

## Routes

### Public (no auth)
```
GET    /products                  list (with filters: category, search, available_until)
GET    /products/{slug}           single product detail
```

### Authenticated customer
```
POST   /marketplace/checkout      create Stripe Checkout session for a cart
GET    /marketplace/orders        list customer's marketplace orders
GET    /marketplace/orders/{id}   detail
POST   /marketplace/orders/{id}/pay-shipping   triggers shipping invoice payment
POST   /marketplace/orders/{id}/cancel         pre-shipping only
```

### Admin (`admin` middleware)
```
# Product CRUD
GET    /admin/products
POST   /admin/products
GET    /admin/products/{id}
PUT    /admin/products/{id}
DELETE /admin/products/{id}
POST   /admin/products/{id}/images
DELETE /admin/products/{id}/images/{imageId}

# Order management
GET    /admin/marketplace-orders
GET    /admin/marketplace-orders/{id}
PUT    /admin/marketplace-orders/{id}/items/{itemId}/mark-received
POST   /admin/marketplace-orders/{id}/assign-box       (sets box_size + price, sends shipping invoice)
POST   /admin/marketplace-orders/{id}/upload-gia
POST   /admin/marketplace-orders/{id}/mark-shipped
POST   /admin/marketplace-orders/{id}/refund

# Returns dashboard (7-day window)
GET    /admin/products/expiring   products with available_until ≤ 7 days from now
```

---

## Stripe Webhook Changes

`StripeWebhookController` already routes invoices/payments by metadata. Add two new types:

```php
case 'marketplace_purchase':
  // From: Stripe Checkout session for cart
  // Action: marketplace_orders.status = 'items_paid', items_paid_at = now()
  // Email customer: "We received your order, packing now"
  break;

case 'marketplace_shipping':
  // From: shipping invoice (sent when admin assigns box)
  // Action: marketplace_orders.status = 'shipping_paid', shipping_paid_at = now()
  // Email customer: "Shipping paid, your box is on its way soon"
  break;
```

---

# Phase 1 Tasks — MVP (Boxly-only inventory)

Goal: ship a working storefront with **only Boxly's inventory**. No third-party sellers yet. Each task is roughly one PR-sized unit.

## 1. Database & Models

- [ ] **1.1** Migration: create `products` table with all fields above
- [ ] **1.2** Migration: create `marketplace_orders` table
- [ ] **1.3** Migration: create `marketplace_order_items` table
- [ ] **1.4** `Product` model — fillable, casts (`images` json, `available_until` date), `belongsTo(User::class, 'seller_id')`, scope `available()`, `inStock()`
- [ ] **1.5** `MarketplaceOrder` model — fillable, casts (`shipping_address`, `box_summary` json), relationships to user + items, status constants, helper `isPaid()`, `needsShippingPayment()`
- [ ] **1.6** `MarketplaceOrderItem` model — fillable, relationships
- [ ] **1.7** Seed a few real Boxly inventory products for testing (separate seeder file)

## 2. Public Product API

- [ ] **2.1** `ProductController::index` — paginated, filters: `category`, `search`, `min_price`, `max_price`, defaults to `status=active` and `available_until > now()`
- [ ] **2.2** `ProductController::show` — by slug, returns full product + images array
- [ ] **2.3** Add public routes to `routes/api.php`
- [ ] **2.4** Add `products/*` to `config/cors.php`

## 3. Marketplace Checkout

- [ ] **3.1** `MarketplaceCheckoutController::create`
  - Receives: `items: [{product_id, quantity}]`, `shipping_address` (optional — only needed if no open order yet)
  - Validates: stock available, all products active and unexpired
  - **Find or create open order**: looks up the user's existing `collecting` order; creates one if none exists
  - Snapshots prices/weights/images at this moment
  - Creates `MarketplaceOrderItem` rows linked to the open order, tagged with the upcoming Stripe payment intent
  - Creates Stripe Checkout Session (one line item per cart item, MXN)
  - Returns `{ checkout_url, order_number }`
- [ ] **3.2** Stock decrement strategy: reserve at checkout creation (decrement `stock`); restore on session expiry/cancel (Stripe webhook + scheduled cleanup)
- [ ] **3.3** Add `success_url` and `cancel_url` to checkout session pointing back to app
- [ ] **3.4** `MarketplaceOrderController::index` — customer's own marketplace orders, paginated
- [ ] **3.5** `MarketplaceOrderController::show` — detail with items + product snapshots
- [ ] **3.6** `MarketplaceOrderController::current` — return the user's currently-open `collecting` order (or null) — used by the storefront to show "in your shipment" badge
- [ ] **3.7** `MarketplaceOrderController::requestShipment` — customer-triggered: moves status from `collecting` → `ready_to_ship`. Validates that all items have been paid for. Sends email to admin/ops queue.

## 4. Webhook Integration

- [ ] **4.1** Add `marketplace_purchase` case to `StripeWebhookController::handleCheckoutSessionCompleted`
  - Find the order's items by `stripe_checkout_session_id`, mark them paid (`status = ordered → ordered`, set `paid_at`)
  - Increment the parent order's `items_subtotal_cents` by the purchase amount
  - Set `items_paid_at` on the order if not already set
  - Send "Purchase confirmed — added to your shipment" email to customer
- [ ] **4.2** Add `marketplace_shipping` case
  - Mark order `shipping_paid`, set `shipping_paid_at`
  - Send "Shipping paid, prepping for shipment" email
- [ ] **4.3** Refund handler: on `charge.refunded` for marketplace orders, restore stock + mark order `refunded`

## 5. Admin Product CRUD

- [ ] **5.1** `Admin\AdminProductController::index` — paginated, all statuses, search, sort
- [ ] **5.2** `AdminProductController::store` — validates required fields including weight + dimensions (used for box estimation)
- [ ] **5.3** `AdminProductController::update` — partial updates, slug regen if name changes
- [ ] **5.4** `AdminProductController::destroy` — soft delete or hard delete (recommend soft for audit; status='inactive' is good enough for MVP)
- [ ] **5.5** Image upload: `POST /admin/products/{id}/images`
  - Reuse the Spaces upload pattern from `EmployeeOrderController::uploadArrivalImages`
  - Multi-file, store path under `products/{slug}/`, append to `images` json
- [ ] **5.6** Image delete: removes from JSON + deletes from Spaces
- [ ] **5.7** Admin route registration

## 6. Admin Marketplace Order Management

- [ ] **6.1** `Admin\AdminMarketplaceOrderController::index` — list with status filter, default to `ready_to_ship` and `packing` (the work queue). Customers in `collecting` are not in the work queue (still shopping).
- [ ] **6.2** `show` — full order detail with items + customer + addresses
- [ ] **6.3** `markItemReceived` — sets item status to `received`, when all items received → order moves to `packing`
- [ ] **6.4** `assignBox` — admin sets `box_size` + `box_price_cents` + `box_summary`
  - Creates Stripe invoice with `metadata.type = 'marketplace_shipping'`
  - Saves `shipping_invoice_id` + `shipping_payment_link`
  - Sets order status to `awaiting_shipping_payment`
  - Sends "Shipping invoice ready" email to customer
- [ ] **6.5** `uploadGia` — same pattern as forwarding orders, stores GIA file + `guia_number`
- [ ] **6.6** `markShipped` — sets `status='shipped'`, `shipped_at`, optional `estimated_delivery_date`
- [ ] **6.7** `refund` — Stripe refund call, restore stock, set `refunded_at`, save `refund_reason`

## 7. Refund Policy & Cancellation

- [ ] **7.1** Customer-initiated cancellation endpoint (only allowed before any item is `received`)
- [ ] **7.2** Refund logic: products refunded; shipping NEVER refunded if already paid
- [ ] **7.3** Restore stock on cancel/refund

## 8. Returns Dashboard (7-day window)

- [ ] **8.1** `Admin\AdminProductController::expiring` — list products with `available_until` ≤ 7 days
- [ ] **8.2** Admin can mark a product as "returned to retailer" (status='inactive', archive flag in metadata)

## 9. Validation & Edge Cases

- [ ] **9.1** Reject checkout if any product is sold out, inactive, or expired (race-safe with DB constraints)
- [ ] **9.2** When the customer's open order weight + new cart weight would exceed 50kg, reject `requestShipment` with a helpful "split into two shipments" message — the cart itself doesn't enforce this since they can keep buying small batches
- [ ] **9.3** Admin warnings when assigning a box that's smaller than total declared weight (sanity check)
- [ ] **9.4** Email templates: order received, shipping invoice ready, shipping paid, shipped, delivered, refunded

## 10. Testing & Observability

- [ ] **10.1** Feature tests: full checkout flow happy path
- [ ] **10.2** Feature tests: refund flow with stock restoration
- [ ] **10.3** Feature tests: cancel flow before/after items received
- [ ] **10.4** Log marketplace state transitions for audit
- [ ] **10.5** Add basic admin dashboard metric: marketplace revenue this period

---

# Phase 1.5 — Ride-Along With Forwarding Orders

The strategic differentiator. While a forwarding order is in `awaiting_packages`, the customer can add Boxly products to that same box for **zero extra shipping**.

- [ ] **1.5.1** New endpoint: `POST /orders/{order}/marketplace-items` — attach products to an existing forwarding order
- [ ] **1.5.2** Constraint: only allowed when order status is `collecting` or `awaiting_packages`
- [ ] **1.5.3** Customer pays only product cost (Stripe Checkout, no shipping line)
- [ ] **1.5.4** Marketplace items appear inside the same forwarding order's pack list
- [ ] **1.5.5** When the forwarding order ships, marketplace items are included automatically
- [ ] **1.5.6** Stock decrement applies same as standalone marketplace
- [ ] **1.5.7** Admin sees marketplace items inline in order detail

---

# Phase 2 — Polish (Post-MVP)

Items that aren't core to the marketplace working but raise quality:

- [ ] Reviews & ratings (`product_reviews` table — buyers can review purchased products)
- [ ] Categories table (FK on products instead of free-text string)
- [ ] Search (start with simple LIKE, can add Meilisearch/Typesense later)
- [ ] Product variants (size, color) — variants table with own stock + price
- [ ] Wishlist
- [ ] Inventory low-stock email alerts for admin
- [ ] Bulk product CSV upload (admin)
- [ ] Tiered/volume pricing (e.g. 50+ units = 10% off)
- [ ] Auto-extract product data from `source_url` (scraping helper for admin upload)

---

## Files Affected (Phase 1)

### New files

```
app/Models/Product.php
app/Models/MarketplaceOrder.php
app/Models/MarketplaceOrderItem.php

app/Http/Controllers/ProductController.php                     (public list/detail)
app/Http/Controllers/MarketplaceCheckoutController.php         (cart → Stripe Checkout)
app/Http/Controllers/MarketplaceOrderController.php            (customer's own orders)
app/Http/Controllers/Admin/AdminProductController.php          (Boxly inventory CRUD)
app/Http/Controllers/Admin/AdminMarketplaceOrderController.php (warehouse workflow)

app/Mail/MarketplaceOrderReceivedMail.php
app/Mail/MarketplaceShippingInvoiceMail.php
app/Mail/MarketplaceShippedMail.php

database/migrations/...create_products_table.php
database/migrations/...create_marketplace_orders_table.php
database/migrations/...create_marketplace_order_items_table.php
database/seeders/MarketplaceProductSeeder.php
```

### Modified files

```
app/Http/Controllers/StripeWebhookController.php   (new metadata cases)
routes/api.php                                       (new route groups)
config/cors.php                                      (add marketplace/* paths)
```

---

## Open Questions for Team Review

1. **Stock reservation timing** — at checkout session creation (recommended) vs payment success? Reservation prevents oversells but needs cleanup on abandoned carts.
2. **Soft vs hard delete** for products — recommend soft (status=inactive) so historical orders keep working
3. **Item-level vs order-level item received tracking** — recommend item-level (matches forwarding pattern)
4. **Refund policy on shipping** — confirm: never refunded once admin has assigned and paid for box
5. **Auto-trigger shipment** — should the system ever auto-flip `collecting` → `ready_to_ship`? E.g. if it's been 30 days since first purchase, or weight ≥ XL? Recommend NO for MVP — let the customer always decide. Can add nudges via email later.
6. **Single open order constraint** — confirm: a customer can have at most one `collecting` order at a time. New purchases always join the existing one.
7. **Refund timing** — refunds allowed at any stage before `shipped`. Restore stock if item not yet `received`; if already received, admin handles physically.

---

## Frontend Counterpart

See `app/tasks/todo.md` for the Nuxt-side plan (storefront, cart, checkout UI, admin product UI, customer order pages).

---

## Review — Phase 1 Implementation Complete

**Migrations + Models** ✅
- `2026_04_05_000000_create_products_table.php` — Product catalog
- `2026_04_05_000001_create_marketplace_orders_table.php` — Shipment lifecycle
- `2026_04_05_000002_create_marketplace_order_items_table.php` — Line items with Stripe payment tracking
- `Product`, `MarketplaceOrder`, `MarketplaceOrderItem` models with scopes + helpers

**Public Product API** ✅
- `StoreProductController` (separate from existing `ProductController` for Stripe boxes)
- `GET /store/products` — paginated, search, category, sort filters
- `GET /store/products/{slug}` — detail by slug + related products
- `GET /store/categories` — pills for filter UI

**Admin Product CRUD** ✅
- `Admin\AdminProductController` — index/show/store/update/destroy
- Multi-image upload to Spaces with stored path + url
- `expiring` endpoint for 7-day clearance dashboard

**Marketplace Checkout** ✅
- `MarketplaceCheckoutController::create` — find-or-create open order, snapshot items, build Stripe Checkout session
- Stock decremented at checkout creation (reservation model)
- Items tagged with `stripe_checkout_session_id` so webhook can flip them paid

**Customer Marketplace Endpoints** ✅
- `MarketplaceOrderController` — index, show, current, requestShipment, cancel
- `current` returns the user's open `collecting` order for storefront context

**Admin Marketplace Order Management** ✅
- `Admin\AdminMarketplaceOrderController` — index, show, mark/unmark item received,
  assign-box (creates Stripe shipping invoice + emails customer), upload-gia,
  mark-shipped, mark-delivered, refund

**Webhooks** ✅
- `checkout.session.completed` → flips items to `ordered`, increments order subtotal,
  sets items_paid_at, sends order-received email
- `invoice.paid` with `marketplace_shipping` metadata → status `shipping_paid`,
  sends shipping-paid email

**Email Mailables** ✅
- `MarketplaceOrderReceivedMail`, `MarketplaceShippingInvoiceMail`,
  `MarketplaceShippingPaidMail`, `MarketplaceShippedMail`
- Blade templates in `resources/views/emails/marketplace/`

**CORS** ✅ — Added `store/*` and `marketplace/*`

**Routes Registered** ✅
- Public: `/store/*`
- Customer (auth): `/marketplace/*`
- Admin (auth + admin): `/admin/products/*`, `/admin/marketplace-orders/*`

**Server-side TODO before launching:**
- Run `php artisan migrate` in production
- (Optional) Seed initial products via tinker or admin UI

**Stripe webhook needs** (already supported by current secret):
- New event subscription: `checkout.session.completed` (added on the existing endpoint)

