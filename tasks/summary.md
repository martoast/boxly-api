# Boxly API - Complete System Documentation

> **Purpose**: This document provides a comprehensive overview of the Boxly API codebase for AI assistants and developers to quickly understand the system architecture, business logic, and implementation details.

---

## Table of Contents
1. [Business Overview](#1-business-overview)
2. [Technology Stack](#2-technology-stack)
3. [Directory Structure](#3-directory-structure)
4. [Database Schema](#4-database-schema)
5. [API Routes](#5-api-routes)
6. [Models & Relationships](#6-models--relationships)
7. [Order Lifecycle](#7-order-lifecycle)
8. [Payment Flow](#8-payment-flow)
9. [Email Notifications](#9-email-notifications)
10. [Affiliate System](#10-affiliate-system)
11. [Purchase Requests](#11-purchase-requests)
12. [Admin Dashboard](#12-admin-dashboard)
13. [Key Controllers](#13-key-controllers)
14. [Authentication & Authorization](#14-authentication--authorization)
15. [File Storage](#15-file-storage)
16. [Docker Setup](#16-docker-setup)

---

## 1. Business Overview

**Boxly** is a **Package Consolidation & Shipping Service** for customers in Mexico who shop from US/international online stores.

### How It Works
1. Customer creates an order and receives a US warehouse address
2. Customer shops online and ships packages to the Boxly warehouse
3. Packages arrive and are tracked/weighed
4. Admin consolidates packages and sends invoice
5. Customer pays for shipping
6. Boxly ships consolidated package to customer's address in Mexico

### User Types
| Type | Description |
|------|-------------|
| `expat` | Foreign nationals living in Mexico |
| `business` | Companies needing B2B solutions |
| `shopper` | Online shoppers buying from US/international stores |

### Order Types
| Type | Description |
|------|-------------|
| `shipping` | Full service - consolidation + delivery to Mexican address |
| `crossing` | Border crossing only - customer picks up at border |

### Warehouse Address
```
482 W. San Ysidro Blvd.
Apt. 123
San Ysidro, CA 92173
United States
Phone: +1 (619) 559-1920
```

---

## 2. Technology Stack

### Backend
- **Framework**: Laravel 10+ (PHP 8.3)
- **Database**: MySQL 8.0
- **Authentication**: Laravel Sanctum (API tokens) + Laravel Fortify
- **Payments**: Laravel Cashier (Stripe integration)
- **Queue**: Laravel Queue (jobs table)
- **Storage**: DigitalOcean Spaces (S3-compatible)

### Development
- **Containerization**: Docker + Laravel Sail
- **Build Tools**: Vite, Tailwind CSS 4.0

---

## 3. Directory Structure

```
boxly-api/
├── app/
│   ├── Http/
│   │   ├── Controllers/         # 23 API controllers
│   │   ├── Middleware/          # AdminMiddleware, JsonResponse
│   │   └── Requests/            # 13 form request validators
│   ├── Models/                  # 12 Eloquent models
│   ├── Mail/                    # 14 email templates
│   ├── Jobs/                    # Async job classes
│   ├── Services/                # AfterShipService (tracking)
│   ├── Actions/                 # Fortify auth actions
│   └── Providers/               # Service providers
├── database/
│   ├── migrations/              # 30+ schema migrations
│   ├── seeders/                 # Database seeders
│   └── factories/               # Model factories
├── routes/
│   └── api.php                  # All API routes (~310 lines)
├── config/                      # Laravel config files
├── docker-compose.yml           # Docker setup
└── storage/                     # Logs, cache, uploads
```

---

## 4. Database Schema

### Core Tables

#### `users`
```php
- id, name, email, password, password_set
- phone, preferred_language (es/en)
- street, exterior_number, interior_number, colonia, municipio, estado, postal_code, full_address
- provider (google/facebook/null)
- role (customer/admin)
- user_type (expat/business/shopper)
- registration_source (JSON - UTM params, campaign info)
- stripe_id, pm_type, pm_last_four, trial_ends_at
```

#### `orders`
```php
- id, user_id, order_number (unique), tracking_number (unique)
- status (collecting/awaiting_packages/packages_complete/awaiting_payment/paid/shipped/delivered/cancelled)
- order_type (shipping/crossing)
- box_size, box_price, currency
- declared_value, iva_amount
- delivery_address (JSON), is_rural, rural_surcharge
- total_weight, actual_weight
- shipping_cost, handling_fee, insurance_fee
- quoted_amount, quote_breakdown (JSON)
- stripe_product_id, stripe_price_id, stripe_invoice_id
- payment_link, amount_paid, paid_at
- deposit_amount, deposit_paid_at, deposit_invoice_id
- consolidation_invoice_id, consolidation_payment_link, consolidation_paid_at
- estimated_delivery_date, actual_delivery_date
- completed_at, processing_started_at, quote_sent_at, shipped_at, delivered_at
- notes
- gia_path, gia_filename, gia_mime_type, gia_size, gia_url (shipping document)
- guia_number (DHL tracking)
- arrival_image_path, arrival_image_url (proof of arrival photo)
```

#### `order_items`
```php
- id, order_id
- product_url, product_name, product_image_url
- retailer, merchant_order_id
- quantity, declared_value
- tracking_number, tracking_url, carrier
- estimated_delivery_date
- arrived (boolean), arrived_at
- weight, dimensions (JSON)
- proof_of_purchase_path/filename/mime_type/size/url
- product_image_path/filename/mime_type/size
```

#### `order_boxes`
```php
- id, order_id
- stripe_price_id, stripe_product_id
- box_size, box_name, box_price, currency
- quantity
- length, width, height, weight
- guia_number
- gia_path, gia_filename, gia_mime_type, gia_size, gia_url
```

#### `purchase_requests`
```php
- id, user_id, request_number
- status (pending_review/quoted/paid/purchased/rejected/cancelled)
- items_total, shipping_cost, sales_tax, processing_fee, total_amount
- currency, payment_method (stripe/manual_deposit)
- stripe_invoice_id, payment_link
- quote_sent_at, paid_at, purchased_at
- admin_notes
```

#### `affiliates`
```php
- id, user_id, affiliate_code (unique)
- commission_type (percentage/fixed), commission_value
- status (pending/active/suspended)
- bank_beneficiary_name, bank_name, bank_account_number
- total_earnings, paid_earnings
```

#### `affiliate_referrals`
```php
- id, affiliate_id, user_id (unique)
- referral_code_used, ip_address
```

#### `affiliate_conversions`
```php
- id, affiliate_id, referral_id, order_id (unique)
- order_amount, commission_amount
- status (pending/approved/rejected)
```

---

## 5. API Routes

### Public Routes
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| GET | `/` | - | Health check |
| GET | `/products` | ProductController@index | List box products from Stripe |
| GET | `/products/{priceId}` | ProductController@show | Get specific product |
| GET | `/user-types` | - | List user type options |
| POST | `/track` | TrackingController@track | Public order tracking |
| POST | `/funnel-capture` | FunnelCaptureController@store | Marketing funnel data |
| POST | `/webhooks/stripe` | StripeWebhookController@handle | Stripe payment webhooks |
| GET | `/affiliate/validate/{code}` | AffiliateController@validateCode | Validate affiliate code |
| POST | `/shipment-tracking/track` | ShipmentTrackingController@track | Track external shipment |
| GET | `/shipment-tracking/carriers` | ShipmentTrackingController@carriers | List supported carriers |

### OAuth Routes (web middleware)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/auth/{provider}/redirect` | OAuth redirect (google/facebook) |
| GET | `/auth/{provider}/callback` | OAuth callback |

### Authenticated Customer Routes (`auth:sanctum`)

#### User
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/user` | Get current user with affiliate info |

#### Profile
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| GET | `/profile` | ProfileController@show | Get profile |
| PUT | `/profile` | ProfileController@update | Update profile |
| GET | `/profile/dashboard` | ProfileController@dashboard | User dashboard stats |

#### Orders
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| GET | `/orders` | OrderController@index | List user's orders |
| POST | `/orders` | OrderController@create | Create new order |
| GET | `/orders/unpaid` | OrderController@unpaidWithQuotes | Orders awaiting payment |
| GET | `/orders/{order}` | OrderController@show | View order details |
| PUT | `/orders/{order}` | OrderController@update | Update order |
| DELETE | `/orders/{order}` | OrderController@destroy | Delete order |
| PUT | `/orders/{order}/complete` | OrderController@complete | Mark as complete |
| PUT | `/orders/{order}/reopen` | OrderController@reopen | Reopen for editing |
| GET | `/orders/{order}/tracking` | OrderController@tracking | Get tracking info |
| GET | `/orders/{order}/quote` | OrderController@viewQuote | View quote |
| POST | `/orders/{order}/pay-quote` | OrderController@payQuote | Get payment link |

#### Order Items
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| POST | `/orders/{order}/items` | OrderItemController@store | Add item |
| PUT | `/orders/{order}/items/{item}` | OrderItemController@update | Update item |
| DELETE | `/orders/{order}/items/{item}` | OrderItemController@destroy | Delete item |
| GET | `/orders/{order}/items/{item}/proof` | OrderItemController@viewProof | View proof of purchase |

#### Payment Methods
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| GET | `/payment-methods` | PaymentMethodController@index | List payment methods |
| POST | `/payment-methods/setup-session` | PaymentMethodController@createSetupSession | Create Stripe setup session |
| POST | `/payment-methods/setup-intent` | PaymentMethodController@createSetupIntent | Create setup intent |
| POST | `/payment-methods` | PaymentMethodController@store | Store payment method |
| DELETE | `/payment-methods/{id}` | PaymentMethodController@destroy | Remove payment method |
| PUT | `/payment-methods/{id}/default` | PaymentMethodController@setDefault | Set as default |

#### Purchase Requests
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| GET | `/purchase-requests` | PurchaseRequestController@index | List requests |
| POST | `/purchase-requests` | PurchaseRequestController@store | Create request |
| GET | `/purchase-requests/{id}` | PurchaseRequestController@show | View request |
| PUT | `/purchase-requests/{id}` | PurchaseRequestController@update | Update request |

#### Affiliate Portal
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| POST | `/affiliate/become` | AffiliateController@become | Become an affiliate |
| GET | `/affiliate/dashboard` | AffiliateController@dashboard | Dashboard stats |
| GET | `/affiliate/referrals` | AffiliateController@referrals | View referrals |
| GET | `/affiliate/conversions` | AffiliateController@conversions | View conversions |
| GET | `/affiliate/payouts` | AffiliateController@payouts | View payouts |
| PUT | `/affiliate/profile` | AffiliateController@updateProfile | Update bank details |

### Admin Routes (`auth:sanctum` + `admin` middleware)

#### Dashboard
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| GET | `/admin/dashboard` | UnifiedAdminDashboardController@index | Main dashboard |
| POST | `/admin/dashboard/manual-metrics` | ...@updateManualMetrics | Update manual metrics |
| GET | `/admin/dashboard/manual-metrics` | ...@getManualMetrics | Get manual metrics |

#### Orders
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| GET | `/admin/orders` | AdminOrderController@index | List all orders |
| GET | `/admin/orders/ready-to-process` | AdminOrderController@readyToProcess | Orders ready to consolidate |
| GET | `/admin/orders/ready-to-ship` | AdminOrderController@readyToShip | Paid orders ready to ship |
| GET | `/admin/orders/ready-for-quote` | AdminQuoteController@ordersReadyForQuote | Orders needing quotes |
| GET | `/admin/orders/{order}` | AdminOrderController@show | View order |
| PUT | `/admin/orders/{order}/status` | AdminOrderController@updateStatus | Update status |
| DELETE | `/admin/orders/{order}` | AdminOrderController@destroy | Delete order |
| DELETE | `/admin/orders/bulk` | AdminOrderController@bulkDestroy | Bulk delete |
| POST | `/admin/orders/merge` | AdminOrderController@mergeOrders | Merge orders |
| POST | `/admin/orders/{order}/consolidate` | AdminOrderController@consolidateOrder | Consolidate & invoice |
| POST | `/admin/orders/{order}/mark-consolidation-paid` | AdminOrderController@markConsolidationPaid | Mark as paid |
| POST | `/admin/orders/{order}/ship` | AdminOrderController@shipOrder | Ship order |
| POST | `/admin/orders/{order}/arrival-image` | AdminOrderController@uploadArrivalImage | Upload arrival photo |
| GET | `/admin/orders/{order}/gia` | AdminOrderController@viewGia | View GIA document |
| PUT | `/admin/orders/{order}/items/mark-all-arrived` | AdminOrderItemController@markAllArrived | Mark all arrived |
| PUT | `/admin/orders/{order}/items/{item}/arrived` | AdminOrderItemController@markArrived | Mark item arrived |

#### Quote/Invoice
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| PUT | `/admin/orders/{order}/process` | AdminQuoteController@markAsProcessing | Start processing |
| POST | `/admin/orders/{order}/prepare-quote` | AdminQuoteController@prepareQuote | Prepare quote |
| POST | `/admin/orders/{order}/send-invoice` | AdminQuoteController@sendInvoice | Send invoice |
| POST | `/admin/orders/{order}/resend-invoice` | AdminQuoteController@resendInvoice | Resend invoice |
| POST | `/admin/orders/{order}/cancel-invoice` | AdminQuoteController@cancelInvoice | Cancel invoice |

#### Order Management (Admin Creates Orders)
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| POST | `/admin/management/orders` | AdminOrderManagementController@createOrder | Create order for customer |
| PUT | `/admin/management/orders/{order}` | AdminOrderManagementController@updateOrder | Update order |
| DELETE | `/admin/management/orders/{order}` | AdminOrderManagementController@deleteOrder | Delete order |
| POST | `/admin/management/orders/{order}/items` | AdminOrderManagementController@addItem | Add item |
| PUT | `/admin/management/orders/{order}/items/{item}` | AdminOrderManagementController@updateItem | Update item |
| DELETE | `/admin/management/orders/{order}/items/{item}` | AdminOrderManagementController@deleteItem | Delete item |

#### Packages
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| GET | `/admin/packages` | AdminOrderItemController@index | List all packages |
| GET | `/admin/packages/pending` | AdminOrderItemController@pending | Pending packages |
| GET | `/admin/packages/missing-weight` | AdminOrderItemController@missingWeight | Missing weight |
| GET | `/admin/packages/expected-today` | AdminOrderItemController@expectedToday | Expected today |
| GET | `/admin/packages/overdue` | AdminOrderItemController@overdue | Overdue packages |
| GET | `/admin/packages/arriving-soon` | AdminOrderItemController@arrivingSoon | Arriving soon |
| GET | `/admin/packages/{item}` | AdminOrderItemController@show | View package |
| PUT | `/admin/packages/{item}` | AdminOrderItemController@update | Update package |

#### Customers
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| GET | `/admin/customers` | AdminCustomerController@index | List customers |
| GET | `/admin/customers/export` | AdminCustomerController@export | Export CSV |
| POST | `/admin/customers` | AdminCustomerController@store | Create customer |
| GET | `/admin/customers/{customer}` | AdminCustomerController@show | View customer |
| PUT | `/admin/customers/{customer}` | AdminCustomerController@update | Update customer |
| GET | `/admin/customers/{customer}/orders` | AdminCustomerController@orders | Customer's orders |
| GET | `/admin/customers/{customer}/collecting-orders` | AdminCustomerController@collectingOrders | Collecting orders |

#### Purchase Requests
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| GET | `/admin/purchase-requests` | AdminPurchaseRequestController@index | List all |
| POST | `/admin/purchase-requests` | AdminPurchaseRequestController@store | Create |
| GET | `/admin/purchase-requests/{id}` | AdminPurchaseRequestController@show | View |
| PUT | `/admin/purchase-requests/{id}` | AdminPurchaseRequestController@update | Update |
| DELETE | `/admin/purchase-requests/{id}` | AdminPurchaseRequestController@destroy | Delete |
| DELETE | `/admin/purchase-requests/bulk` | AdminPurchaseRequestController@bulkDestroy | Bulk delete |
| POST | `/admin/purchase-requests/merge` | AdminPurchaseRequestController@mergePurchaseRequests | Merge |
| POST | `/admin/purchase-requests/{id}/quote` | AdminPurchaseRequestController@createQuote | Create quote |
| POST | `/admin/purchase-requests/{id}/mark-purchased` | AdminPurchaseRequestController@markAsPurchased | Mark purchased |
| PUT | `/admin/purchase-requests/{id}/reject` | AdminPurchaseRequestController@reject | Reject |

#### Business Expenses
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| GET | `/admin/expenses` | AdminBusinessExpenseController@index | List expenses |
| POST | `/admin/expenses` | AdminBusinessExpenseController@store | Create expense |
| GET | `/admin/expenses/categories` | AdminBusinessExpenseController@categories | List categories |
| POST | `/admin/expenses/bulk-import` | AdminBusinessExpenseController@bulkImport | Bulk import |
| GET | `/admin/expenses/{expense}` | AdminBusinessExpenseController@show | View expense |
| PUT | `/admin/expenses/{expense}` | AdminBusinessExpenseController@update | Update expense |
| DELETE | `/admin/expenses/{expense}` | AdminBusinessExpenseController@destroy | Delete expense |

#### Boxes
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| GET | `/admin/boxes` | AdminOrderBoxController@index | List boxes |
| GET | `/admin/boxes/{box}` | AdminOrderBoxController@show | View box |

#### Affiliates
| Method | Endpoint | Controller | Description |
|--------|----------|------------|-------------|
| GET | `/admin/affiliates` | AdminAffiliateController@index | List affiliates |
| POST | `/admin/affiliates` | AdminAffiliateController@store | Create affiliate |
| GET | `/admin/affiliates/{affiliate}` | AdminAffiliateController@show | View affiliate |
| PUT | `/admin/affiliates/{affiliate}` | AdminAffiliateController@update | Update affiliate |
| DELETE | `/admin/affiliates/{affiliate}` | AdminAffiliateController@destroy | Delete affiliate |
| GET | `/admin/affiliates/{affiliate}/conversions` | AdminAffiliateController@conversions | View conversions |
| GET | `/admin/affiliates/{affiliate}/payouts` | AdminAffiliateController@payouts | View payouts |
| POST | `/admin/affiliates/{affiliate}/record-payout` | AdminAffiliateController@recordPayout | Record payout |

---

## 6. Models & Relationships

### User
```php
// Relationships
hasMany(Order::class)
hasOne(Affiliate::class)
hasOne(AffiliateReferral::class)

// Key Methods
isAdmin(): bool
isAffiliate(): bool
wasReferred(): bool
hasCompleteAddress(): bool

// Traits
HasApiTokens, Notifiable, Billable (Stripe)
```

### Order
```php
// Relationships
belongsTo(User::class)
hasMany(OrderItem::class)
hasMany(OrderBox::class)

// Status Constants
STATUS_COLLECTING = 'collecting'
STATUS_AWAITING_PACKAGES = 'awaiting_packages'
STATUS_PACKAGES_COMPLETE = 'packages_complete'
STATUS_AWAITING_PAYMENT = 'awaiting_payment'
STATUS_PAID = 'paid'
STATUS_PROCESSING = 'processing' // Legacy
STATUS_SHIPPED = 'shipped'
STATUS_DELIVERED = 'delivered'
STATUS_CANCELLED = 'cancelled'

// Key Methods
markAsComplete()           // collecting -> awaiting_packages
reopenForEditing()         // -> collecting
allItemsArrived(): bool
allItemsWeighed(): bool
calculateTotalBoxPrice(): float
calculateDepositAmount(): float  // 50% of box price
isCrossingOnly(): bool
isShipping(): bool
hasMultipleBoxes(): bool
hasArrivalImage(): bool
```

### OrderItem
```php
// Relationships
belongsTo(Order::class)

// Carriers
ups, fedex, usps, amazon, dhl, ontrac, lasership, other, unknown

// Key Methods
markAsArrived()
markAsNotArrived()
updateMeasurements(weight, dimensions)
detectCarrier(): string      // Auto-detect from tracking number
extractRetailer(): string    // Extract from product URL
```

### OrderBox
```php
// Relationships
belongsTo(Order::class)

// Key Methods
hasGia(): bool
deleteGia()
```

### Affiliate
```php
// Relationships
belongsTo(User::class)
hasMany(AffiliateReferral::class)
hasMany(AffiliateConversion::class)
hasMany(AffiliatePayout::class)

// Commission Types
COMMISSION_TYPE_PERCENTAGE = 'percentage'  // e.g., 10% of box price
COMMISSION_TYPE_FIXED = 'fixed'            // e.g., $50 per order

// Key Methods
calculateCommission(boxPrice): float
generateCode(name): string   // Creates "ALEX23" style codes
getPendingEarningsAttribute(): float
```

---

## 7. Order Lifecycle

### Status Flow Diagram
```
┌─────────────────────────────────────────────────────────────────┐
│                          CUSTOMER ACTIONS                        │
└─────────────────────────────────────────────────────────────────┘

    ┌──────────────┐
    │  COLLECTING  │  Customer adds items, gets warehouse address
    └──────┬───────┘
           │ customer.complete()
           ▼
    ┌──────────────────────┐
    │  AWAITING_PACKAGES   │  Items shipping to warehouse
    └──────────┬───────────┘
               │ (can reopen to add more items)
               │

┌─────────────────────────────────────────────────────────────────┐
│                           ADMIN ACTIONS                          │
└─────────────────────────────────────────────────────────────────┘

               │ admin marks items arrived
               │ admin uploads arrival image
               ▼
    ┌───────────────────────┐
    │   PACKAGES_COMPLETE   │  All items at warehouse, ready to process
    └───────────┬───────────┘
                │ admin.consolidateOrder(boxes)
                │ creates invoice, sends email
                ▼
    ┌───────────────────────┐
    │   AWAITING_PAYMENT    │  Invoice sent to customer
    └───────────┬───────────┘
                │ customer pays (Stripe webhook) OR
                │ admin.markConsolidationPaid()
                ▼
    ┌──────────────┐
    │     PAID     │  Ready to ship
    └──────┬───────┘
           │ admin.shipOrder(boxes with GIA files)
           ▼
    ┌──────────────┐
    │   SHIPPED    │  In transit to customer (shipping orders)
    └──────┬───────┘        OR
           │         ┌──────────────┐
           │         │  DELIVERED   │  Crossing orders skip to delivered
           ▼         └──────────────┘
    ┌──────────────┐
    │  DELIVERED   │  Order complete
    └──────────────┘
```

### Key Status Rules
- **Collecting**: Customer can add/edit/delete items
- **Awaiting Packages**: Items in transit, can reopen
- **Packages Complete**: Triggered by admin uploading arrival image
- **Awaiting Payment**: Invoice sent, waiting for payment
- **Paid**: Payment received (Stripe webhook or manual)
- **Shipped**: For shipping orders, in transit with DHL
- **Delivered**: Order complete, crossing orders skip shipped

---

## 8. Payment Flow

### Current Flow (100% at Consolidation)
```
1. Customer creates order (free)
2. Items arrive at warehouse
3. Admin consolidates order:
   - Selects box sizes from Stripe products
   - Creates invoice for 100% of box price
   - Sends email with payment link
4. Customer pays via Stripe or manual bank transfer
5. Admin ships order
```

### Stripe Integration

#### Products
Box sizes are stored as Stripe Products/Prices:
- Extra Small Box
- Small Box
- Medium Box
- Large Box
- Extra Large Box

Prices are in MXN.

#### Webhook Handler (`StripeWebhookController`)
Handles `invoice.paid` events with metadata types:
- `box_payment`: Main consolidation payment
- `deposit`: Legacy 50% deposit flow
- `final_invoice` / `order_invoice` / `full_payment`: Legacy flows
- `purchase_request_invoice`: Purchase request payments

#### Key Invoice Metadata
```php
'type' => 'box_payment',
'order_id' => $order->id,
'order_number' => $order->order_number,
'box_count' => count($boxes),
'total_box_price' => $totalPrice
```

---

## 9. Email Notifications

### Order Emails
| Mailable | Trigger | Description |
|----------|---------|-------------|
| `OrderCreated` | Order created | Welcome email with warehouse address |
| `OrderStatusChanged` | Status change | Generic status update |
| `PackageArrived` | Item marked arrived | Individual package notification |
| `AllPackagesArrived` | Arrival image uploaded | All packages at warehouse |
| `OrderConsolidatedInvoice` | Consolidation | Invoice with payment link |
| `PaymentReceived` | Payment confirmed | Payment confirmation |
| `DepositReceived` | Legacy deposit paid | Deposit confirmation |
| `OrderShipped` | Order shipped | Tracking info for shipping |
| `OrderShippedWithDeposit` | Legacy | Shipped with deposit |

### Purchase Request Emails
| Mailable | Trigger | Description |
|----------|---------|-------------|
| `PurchaseRequestCreated` | Request created | Confirmation |
| `PurchaseRequestQuoteSent` | Quote sent | Quote with payment link |
| `PurchaseRequestPaymentReceived` | Payment received | Payment confirmation |
| `PurchaseRequestItemsPurchased` | Items purchased | Tracking info |

### Automatic Email Triggers
The `Order` model automatically sends `OrderStatusChanged` emails via the `updated` event, except:
- `packages_complete` - Handled by consolidation email
- `paid` - Handled by `PaymentReceived` email

Skip emails with: `$order->skipEmailNotifications = true`

---

## 10. Affiliate System

### How It Works
1. User becomes affiliate via `/affiliate/become`
2. Gets unique code (e.g., `ALEX23`) and referral link
3. New users register with referral code
4. When referred user's order is paid, affiliate earns commission
5. Admin records manual payouts

### Commission Calculation
```php
// Percentage (default 10%)
$commission = $boxPrice * ($commissionValue / 100);

// Fixed amount
$commission = $commissionValue;
```

### Tracking Flow
1. `AffiliateReferral` created when user registers with code
2. `AffiliateConversion` created when order is paid
3. `Affiliate.total_earnings` incremented
4. `AffiliatePayout` recorded by admin

### Affiliate Code Generation
```php
// Generates personal codes like "ALEX23"
$code = strtoupper($firstName . rand(10, 9999));
```

---

## 11. Purchase Requests

### Purpose
Allows customers to request Boxly to purchase items on their behalf.

### Status Flow
```
pending_review -> quoted -> paid -> purchased -> (completed)
                      \-> rejected
                      \-> cancelled
```

### Payment Methods
- `stripe` - Online payment via Stripe invoice
- `manual_deposit` - Bank transfer (customer sends to NU bank account)

---

## 12. Admin Dashboard

### Unified Dashboard (`UnifiedAdminDashboardController`)
Provides metrics for:
- Orders by status
- Revenue statistics
- Package tracking
- Customer counts
- Business expenses

### Manual Metrics
Admins can enter manual metrics for:
- Monthly revenue
- Box counts
- Custom KPIs

---

## 13. Key Controllers

### OrderController (Customer)
- `create()`: Creates order, generates tracking number, sends welcome email
- `complete()`: Marks order complete, moves to awaiting_packages
- `reopen()`: Reopens for editing (only from awaiting_packages or packages_complete)

### AdminOrderController
- `consolidateOrder()`: Selects boxes, creates Stripe invoice, sends email
- `markConsolidationPaid()`: For manual payments
- `shipOrder()`: Uploads GIA files, sets guia numbers, marks as shipped
- `uploadArrivalImage()`: Triggers packages_complete status

### StripeWebhookController
- `handleInvoicePaid()`: Processes all Stripe payments
- `trackAffiliateConversion()`: Creates affiliate conversion records

---

## 14. Authentication & Authorization

### Authentication
- **Laravel Sanctum**: API token authentication
- **Laravel Fortify**: Registration, login, password reset
- **OAuth**: Google and Facebook via Socialite

### Authorization
- **AdminMiddleware**: Checks `user->role === 'admin'`
- **Order Ownership**: Controllers verify `order->user_id === auth()->id()`

### Middleware Stack
```php
// Customer routes
Route::middleware('auth:sanctum')->group(function () {
    // ...
});

// Admin routes
Route::middleware('admin')->prefix('admin')->group(function () {
    // ...
});
```

---

## 15. File Storage

### DigitalOcean Spaces (S3-compatible)
Configuration in `config/filesystems.php`:
```php
'spaces' => [
    'driver' => 's3',
    'url' => env('DO_SPACES_URL'),
    // ...
]
```

### File Types Stored
| File Type | Location | Purpose |
|-----------|----------|---------|
| GIA Documents | `users/{slug}/orders/{orderNum}/boxes/{idx}/` | Shipping documents |
| Arrival Images | `users/{slug}/orders/{orderNum}/` | Proof of package arrival |
| Proof of Purchase | `users/{slug}/orders/{orderNum}/items/{id}/` | Purchase receipts |
| Product Images | `users/{slug}/orders/{orderNum}/items/{id}/` | Item photos |

---

## 16. Docker Setup

### docker-compose.yml
```yaml
services:
  laravel.test:
    build: './vendor/laravel/sail/runtimes/8.3'
    image: 'sail-8.3/app'
    ports:
      - '${APP_PORT:-80}:80'
    volumes:
      - '.:/var/www/html'
      - 'sail-storage:/var/www/html/storage'
    depends_on:
      - mysql

  mysql:
    image: 'mysql/mysql-server:8.0'
    ports:
      - '${FORWARD_DB_PORT:-3306}:3306'
    volumes:
      - 'sail-mysql:/var/lib/mysql'
    healthcheck:
      test: ['CMD', 'mysqladmin', 'ping']

volumes:
  sail-mysql:
  sail-storage:
```

### Commands
```bash
# Start development environment
./vendor/bin/sail up -d

# Run migrations
./vendor/bin/sail artisan migrate

# Run queue worker
./vendor/bin/sail artisan queue:work
```

---

## Quick Reference

### Order Number Format
`{YY}{RANDOM6}` - e.g., `24ABC123`

### Tracking Number Format
`TRK{RANDOM6}` - e.g., `TRKABC123`

### Supported Carriers
UPS, FedEx, USPS, Amazon, DHL, OnTrac, LaserShip

### Box Sizes
extra-small, small, medium, large, extra-large

### User Roles
customer, admin

### Currencies
Primary: MXN (Mexican Pesos)

---

## Important Business Rules

1. **Tracking Number Required**: Must be included in all shipments to warehouse
2. **IVA Tax**: 16% on declared values over $50 USD
3. **Rural Surcharge**: Additional fee for rural delivery addresses
4. **Multiple Boxes**: Orders can have multiple boxes of different sizes
5. **Crossing Orders**: Skip shipping, go directly to delivered status
6. **Affiliate Commissions**: Tracked when orders are paid
7. **Email Suppression**: Use `skipEmailNotifications = true` for admin operations

---

*Last Updated: January 2026*
