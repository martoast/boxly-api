# Affiliate Marketing System Implementation Plan

## Overview
Build a simple affiliate system where influencers can refer users to Boxly and earn commissions on paid orders.

## Core Flow
1. Influencer signs up � gets unique affiliate code (e.g., `?ref=INFLUENCER123`)
2. User clicks affiliate link � code stored in cookie/session � user registers
3. User creates order and pays � affiliate gets credit for the conversion
4. Admin can track and manage payouts

---

## Phase 1: Database Schema

### Task 1.1: Create `affiliates` table
- [ ] Migration with fields:
  - `id`
  - `user_id` (required - **every affiliate MUST be a user**)
  - `affiliate_code` (unique, e.g., "ABC123")
  - `commission_type` (enum: 'percentage', 'fixed') - default 'percentage'
  - `commission_value` (decimal) - default 10.00 (means 10% or $10 fixed)
  - `status` (enum: 'pending', 'active', 'suspended') - default 'active' (auto-approved)
  - **Bank Details for Payouts:**
    - `bank_beneficiary_name` (string, nullable)
    - `bank_name` (string, nullable)
    - `bank_account_number` (string, nullable)
  - `total_earnings` (decimal, default 0) - lifetime earnings
  - `paid_earnings` (decimal, default 0) - already paid out
  - `timestamps`

**Note:** Name, email, phone come from the linked User record - no duplication!
**Note:** `pending_earnings` = `total_earnings` - `paid_earnings` (calculated, not stored)

### Task 1.2: Create `affiliate_referrals` table
- [ ] Migration with fields:
  - `id`
  - `affiliate_id`
  - `user_id` (the referred user)
  - `referral_code_used` (store the code at time of referral)
  - `ip_address` (optional, for fraud detection)
  - `timestamps`

### Task 1.3: Create `affiliate_conversions` table
- [ ] Migration with fields:
  - `id`
  - `affiliate_id`
  - `referral_id` (links to affiliate_referrals)
  - `order_id`
  - `order_amount` (decimal) - total box price
  - `commission_amount` (decimal) - calculated commission
  - `status` (enum: 'pending', 'approved', 'rejected') - default 'approved'
  - `timestamps`

### Task 1.4: Create `affiliate_payouts` table
- [ ] Migration with fields:
  - `id`
  - `affiliate_id`
  - `amount` (decimal) - amount transferred
  - `notes` (text, nullable) - admin can add transfer reference, etc.
  - `paid_by` (user_id of admin who recorded it)
  - `timestamps`

**This table tracks history of all manual bank transfers to affiliates**

---

## Phase 2: Models & Relationships

### Task 2.1: Create Affiliate model
- [ ] Model with relationships:
  - `user()` - belongsTo User (optional)
  - `referrals()` - hasMany AffiliateReferral
  - `conversions()` - hasMany AffiliateConversion
  - `payouts()` - hasMany AffiliatePayout
- [ ] Methods:
  - `generateCode()` - create unique affiliate code
  - `calculateCommission($boxPrice)` - returns commission based on type/value
  - `getPendingEarningsAttribute()` - returns `total_earnings - paid_earnings`

### Task 2.2: Create AffiliateReferral model
- [ ] Model with relationships:
  - `affiliate()` - belongsTo Affiliate
  - `user()` - belongsTo User

### Task 2.3: Create AffiliateConversion model
- [ ] Model with relationships:
  - `affiliate()` - belongsTo Affiliate
  - `referral()` - belongsTo AffiliateReferral
  - `order()` - belongsTo Order

### Task 2.4: Create AffiliatePayout model
- [ ] Model with relationships:
  - `affiliate()` - belongsTo Affiliate
  - `paidBy()` - belongsTo User (the admin)

### Task 2.5: Add relationships to User model
- [ ] Add `affiliateReferral()` - hasOne AffiliateReferral (user was referred by an affiliate)
- [ ] Add `affiliate()` - hasOne Affiliate (user IS an affiliate themselves)
- [ ] Add `isAffiliate()` helper method - check if user has an affiliate account

---

## Phase 3: Referral Tracking (Critical Path)

### Task 3.1: Create endpoint to validate affiliate code
- [ ] `GET /api/affiliate/validate/{code}` - public endpoint
  - Returns affiliate name/info if valid
  - Frontend uses this to show "Referred by X" message

### Task 3.2: Modify user registration to accept referral code
- [ ] Add `referred_by` parameter to registration
- [ ] When user registers with valid code:
  - Create `AffiliateReferral` record linking user to affiliate
  - Store in `registration_source` JSON: `{"affiliate_code": "CODE123"}`

---

## Phase 4: Conversion Tracking (StripeWebhookController)

### Task 4.1: Track conversions on payment
- [ ] In `handleOrderPaid()` and `handleInvoicePaid()`:
  - Check if order's user has an affiliate referral
  - If yes, create `AffiliateConversion` record
  - Calculate commission based on **total box price** (`$order->calculateTotalBoxPrice()`)
    - If `commission_type` = 'percentage': `box_price * (commission_value / 100)`
    - If `commission_type` = 'fixed': `commission_value`
  - Update affiliate's `total_earnings`

**Key code location:** `app/Http/Controllers/StripeWebhookController.php`

```php
// After order is marked as paid:
$this->trackAffiliateConversion($order);
```

**Commission Example (10% default):**
- User pays for 2 Large Boxes @ $65 each = $130 total box price
- Affiliate earns: $130 × 10% = $13 commission

---

## Phase 5: Affiliate API Endpoints

### Task 5.1: Public affiliate routes
- [ ] `GET /api/affiliate/validate/{code}` - validate code exists (for frontend to show "Referred by X")

### Task 5.2: Become an affiliate (two paths)

**Path A: New user registers AND wants to be affiliate (registration page checkbox)**
- [ ] Modify existing registration to accept optional `become_affiliate` flag
  - If `become_affiliate: true` → also pass bank details
  - Creates User + Affiliate in one step
  - Returns: user with affiliate data + code + referral link

**Path B: Existing user becomes affiliate (banner CTA in dashboard)**
- [ ] `POST /api/affiliate/become` (auth:sanctum required)
  - Input: bank details (beneficiary name, bank name, account number)
  - Creates Affiliate record linked to logged-in user
  - Auto-generate unique affiliate code
  - **Status starts as 'active' immediately**
  - Returns: affiliate data + code + referral link

### Task 5.3: Affiliate Portal routes (auth:sanctum - same login as regular users)
- [ ] `GET /api/affiliate/dashboard` - main portal stats (requires user to be an affiliate):
  - Total referrals (users brought in)
  - Total conversions (paid orders)
  - Total earnings (lifetime)
  - Pending payout (not yet paid)
  - Already paid out
  - Their unique affiliate code
  - Their custom referral link (e.g., `https://boxly.com?ref=ABC123`)
- [ ] `GET /api/affiliate/referrals` - list of users they referred
  - User name, signup date, number of orders
- [ ] `GET /api/affiliate/conversions` - list of conversions/sales
  - Order ID, date, box price, commission earned, status
- [ ] `GET /api/affiliate/payouts` - payout history
  - Amount, date, notes/reference
- [ ] `PUT /api/affiliate/profile` - update their bank details

---

## Phase 6: Admin Management

### Task 6.1: Admin affiliate routes
- [ ] `GET /api/admin/affiliates` - list all affiliates (with pending earnings, linked user info)
- [ ] `GET /api/admin/affiliates/{affiliate}` - view affiliate details + bank info + linked user
- [ ] `POST /api/admin/affiliates` - create new affiliate
  - Option A: From existing user → pass `user_id`, auto-fill name/email from user
  - Option B: Standalone → pass name, email, phone manually
  - Admin fills in: bank details, commission settings
  - Status can be set to 'active' immediately (skip pending)
- [ ] `PUT /api/admin/affiliates/{affiliate}` - update affiliate:
  - Status (pending/active/suspended)
  - Commission type & value (percentage or fixed amount)
  - Bank details
  - Can link to existing user via `user_id`
- [ ] `DELETE /api/admin/affiliates/{affiliate}` - remove affiliate
- [ ] `GET /api/admin/affiliates/{affiliate}/conversions` - view all conversions
- [ ] `POST /api/admin/affiliates/{affiliate}/record-payout` - record manual bank transfer
  - Input: `amount`, `notes` (optional - transfer reference)
  - Action: Increases `paid_earnings`, creates payout record
  - Admin transfers money manually via bank, then records it here
- [ ] `GET /api/admin/affiliates/{affiliate}/payouts` - view payout history

---

## Implementation Priority

**MVP (Start Here):**
1. Database migrations (Tasks 1.1-1.3)
2. Models (Tasks 2.1-2.4)
3. Validate affiliate code endpoint (Task 3.1)
4. Registration with referral code (Task 3.2)
5. Conversion tracking in webhook (Task 4.1)

**Phase 2:**
6. Affiliate dashboard endpoints (Task 5.2)
7. Admin management endpoints (Task 6.1)

**Nice to Have:**
- Affiliate application form
- Email notifications on conversions
- Payout automation
- Multi-tier commissions

---

## Decisions Made

1. **Commission structure:** Configurable per affiliate - percentage OR fixed amount, default 10% of total box price
2. **Payout method:** Manual bank transfer - admin transfers money, then records it in system
3. **Bank details stored:** Beneficiary name, bank name, account number
4. **Who can be affiliates:** Anyone can sign up to become an affiliate
   - If email matches existing user → create affiliate linked to that user
   - If email is NEW → auto-create User account, then create affiliate
   - **Every affiliate MUST be a user** - no standalone affiliates
5. **Cookie duration:** 30 days - frontend stores referral code for 30 days
6. **Commission triggers:** ALL lifetime orders from referred users (not just first order)
7. **User-Affiliate connection:** Strong link between users and affiliates
   - Best customers who order a lot can become affiliates
   - User model has `affiliate()` relationship to check if user is an affiliate
   - Affiliate model has `user()` relationship to access user details
8. **Admin creation:** Admin can create affiliates from existing users
   - Select user → auto-fill name/email → add bank details → activate immediately
9. **Self-signup:** Auto-approved as 'active' - starts earning immediately
10. **Single login system:**
    - Affiliates log in as normal users (same auth)
    - User object includes `affiliate` if they are one
    - No separate affiliate login needed
11. **Two paths to become affiliate:**
    - **New users:** Checkbox on registration "I want to be an affiliate" + bank details
    - **Existing users:** Banner in dashboard → click → provide bank details → become affiliate
12. **Affiliate Portal:** Affiliates get their own dashboard to view:
    - Performance stats (referrals, conversions, earnings)
    - Users they brought in
    - Sales they generated
    - Payout history
    - Their unique code and custom referral link

---

## Files to Create/Modify

**New files:**
- `database/migrations/xxxx_create_affiliate_tables.php`
- `app/Models/Affiliate.php`
- `app/Models/AffiliateReferral.php`
- `app/Models/AffiliateConversion.php`
- `app/Models/AffiliatePayout.php`
- `app/Http/Controllers/AffiliateController.php`
- `app/Http/Controllers/AdminAffiliateController.php`
- `app/Services/AffiliateService.php` (optional - for conversion tracking logic)

**Files to modify:**
- `app/Http/Controllers/StripeWebhookController.php` - add conversion tracking
- `app/Models/User.php` - add affiliateReferral relationship
- `routes/api.php` - add affiliate routes

---

## Review

### Implementation Complete - December 17, 2025

**All core affiliate system features have been implemented:**

#### Files Created:
1. `database/migrations/2025_12_17_225918_create_affiliate_tables.php` - 4 tables (affiliates, affiliate_referrals, affiliate_conversions, affiliate_payouts)
2. `app/Models/Affiliate.php` - Main affiliate model with commission calculation
3. `app/Models/AffiliateReferral.php` - Tracks referred users
4. `app/Models/AffiliateConversion.php` - Tracks paid orders from referrals
5. `app/Models/AffiliatePayout.php` - Tracks manual bank payouts
6. `app/Http/Controllers/AffiliateController.php` - Affiliate portal endpoints
7. `app/Http/Controllers/AdminAffiliateController.php` - Admin management endpoints

#### Files Modified:
1. `app/Models/User.php` - Added `affiliate()`, `affiliateReferral()`, `isAffiliate()`, `wasReferred()`
2. `app/Http/Controllers/StripeWebhookController.php` - Added `trackAffiliateConversion()` method
3. `app/Http/Controllers/AdminOrderController.php` - Added `trackAffiliateConversion()` for admin manual payments
4. `routes/api.php` - Added all affiliate routes + updated /user endpoint to include affiliate data

#### API Endpoints Created:

**Public:**
- `GET /api/affiliate/validate/{code}` - Validate affiliate code

**Authenticated (User Portal):**
- `POST /api/affiliate/become` - Become an affiliate
- `GET /api/affiliate/dashboard` - View stats
- `GET /api/affiliate/referrals` - View referred users
- `GET /api/affiliate/conversions` - View sales/commissions
- `GET /api/affiliate/payouts` - View payout history
- `PUT /api/affiliate/profile` - Update bank details

**Admin:**
- `GET /api/admin/affiliates` - List all affiliates
- `POST /api/admin/affiliates` - Create affiliate from user
- `GET /api/admin/affiliates/{id}` - View affiliate
- `PUT /api/admin/affiliates/{id}` - Update affiliate
- `DELETE /api/admin/affiliates/{id}` - Delete affiliate
- `GET /api/admin/affiliates/{id}/conversions` - View conversions
- `GET /api/admin/affiliates/{id}/payouts` - View payouts
- `POST /api/admin/affiliates/{id}/record-payout` - Record bank transfer

#### How It Works:
1. User becomes affiliate via `POST /api/affiliate/become` or admin creates one
2. Affiliate gets unique code (e.g., `ABC123`) and referral link
3. New user registers with `?ref=ABC123` → frontend calls `AffiliateController::trackReferral()`
4. When referred user pays for order → conversion is tracked:
   - **Stripe payment:** `StripeWebhookController::trackAffiliateConversion()`
   - **Admin marks paid:** `AdminOrderController::trackAffiliateConversion()`
5. Commission calculated from total box price (10% default)
6. Admin records payout after manual bank transfer

#### Still Needed (Frontend):
- [ ] Frontend: Store referral code in cookie/localStorage for 30 days
- [ ] Frontend: Call `trackReferral()` during user registration when code present
- [ ] Frontend: Affiliate portal UI
- [ ] Frontend: Admin affiliate management UI
- [ ] Frontend: "Become an Affiliate" banner for existing users

#### To Run Migration:
```bash
php artisan migrate
```
