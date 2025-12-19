# Affiliate System Frontend Implementation Plan

## Overview
Frontend implementation for the affiliate marketing system. Affiliates can view their dashboard, track referrals/conversions, and manage their profile. Admins can manage all affiliates.

---

## Routes to Create

| Route | Component | Description |
|-------|-----------|-------------|
| `/app/affiliate/` | AffiliateDashboard | User's affiliate portal |
| `/app/admin/affiliates` | AdminAffiliateList | Admin list of all affiliates |
| `/app/admin/affiliates/:id` | AdminAffiliateView | Admin view single affiliate |
| `/app/admin/affiliates/:id/edit` | AdminAffiliateEdit | Admin edit affiliate |

---

## Phase 1: User Affiliate Portal

### Task 1.1: Affiliate Dashboard Page (`/app/affiliate/`)
- [ ] Create `AffiliateDashboard` component
- [ ] Show stats cards:
  - Total Referrals (users brought in)
  - Total Conversions (paid orders)
  - Total Earnings (lifetime)
  - Pending Payout (not yet paid)
  - Already Paid Out
- [ ] Display affiliate code prominently with copy button
- [ ] Display referral link with copy button: `https://boxly.com?ref=ABC123`
- [ ] Tabs for: Referrals | Conversions | Payouts

**API Calls:**
```javascript
GET /affiliate/dashboard
GET /affiliate/referrals
GET /affiliate/conversions
GET /affiliate/payouts
```

### Task 1.2: Referrals Tab
- [ ] Table showing users they referred:
  - User name
  - Signup date
  - Number of orders (from API)
- [ ] Pagination

### Task 1.3: Conversions Tab
- [ ] Table showing sales/commissions:
  - Order ID/Number
  - Date
  - Box Price (order_amount)
  - Commission Earned
  - Status (approved/pending/rejected)
- [ ] Pagination

### Task 1.4: Payouts Tab
- [ ] Table showing payout history:
  - Amount
  - Date
  - Notes/Reference
- [ ] Pagination

### Task 1.5: Bank Details Section
- [ ] Form to update bank details:
  - Beneficiary Name
  - Bank Name
  - Account Number
- [ ] Save button calls `PUT /affiliate/profile`

---

## Phase 2: Become an Affiliate (Two Paths)

### Task 2.1: Registration Page Update
- [ ] Add checkbox: "I want to be an affiliate and earn commissions"
- [ ] When checked, show bank details fields:
  - Beneficiary Name (optional)
  - Bank Name (optional)
  - Account Number (optional)
- [ ] Pass `become_affiliate: true` and bank details to registration API
- [ ] Registration API should create user + affiliate in one call

**Note:** This requires backend update to handle `become_affiliate` flag in registration.

### Task 2.2: Existing User Banner (Dashboard)
- [ ] Show banner in user dashboard if `is_affiliate === false`:
  - "Earn money by referring friends! Become an affiliate today."
  - CTA button: "Become an Affiliate"
- [ ] On click, show modal/form for bank details
- [ ] Submit calls `POST /affiliate/become`
- [ ] On success, refresh user data and redirect to `/app/affiliate/`

### Task 2.3: Referral Code Cookie Handling
- [ ] On any page load, check for `?ref=XXX` query param
- [ ] If present, validate code: `GET /affiliate/validate/{code}`
- [ ] If valid, store in localStorage with 30-day expiry:
  ```javascript
  localStorage.setItem('affiliate_ref', JSON.stringify({
    code: 'ABC123',
    expires: Date.now() + (30 * 24 * 60 * 60 * 1000)
  }))
  ```
- [ ] On registration, read from localStorage and pass to API if not expired

---

## Phase 3: Admin Affiliate Management

### Task 3.1: Admin Affiliates List (`/app/admin/affiliates`)
- [ ] Create `AdminAffiliateList` component
- [ ] Table with columns:
  - Affiliate Code
  - User Name
  - Email
  - Phone
  - Status (badge: active/pending/suspended)
  - Commission (e.g., "10%" or "$5 fixed")
  - Total Earnings
  - Pending Payout
  - Referrals Count
  - Conversions Count
  - Created Date
  - Actions (View | Edit | Delete)
- [ ] Filters:
  - Status dropdown (all/active/pending/suspended)
  - Search by name/email/code
  - "Has Pending Earnings" toggle
- [ ] Pagination
- [ ] "Create Affiliate" button (from existing user)

**API Call:**
```javascript
GET /admin/affiliates?status=active&search=john&has_pending_earnings=true
```

### Task 3.2: Create Affiliate Modal
- [ ] Search/select existing user (dropdown with search)
- [ ] Once user selected, show form:
  - Commission Type (percentage/fixed) - default percentage
  - Commission Value - default 10
  - Status (active/pending/suspended) - default active
  - Bank Details (optional):
    - Beneficiary Name
    - Bank Name
    - Account Number
- [ ] Submit calls `POST /admin/affiliates`

### Task 3.3: View Affiliate Page (`/app/admin/affiliates/:id`)
- [ ] Create `AdminAffiliateView` component
- [ ] Show affiliate info:
  - User info (name, email, phone)
  - Affiliate code + referral link (with copy buttons)
  - Status badge
  - Commission settings
  - Bank details (masked account number?)
- [ ] Stats cards:
  - Total Referrals
  - Total Conversions
  - Approved Conversions
  - Total Earnings
  - Paid Earnings
  - Pending Payout
- [ ] Tabs: Conversions | Payouts
- [ ] Buttons: Edit | Record Payout | Delete

**API Call:**
```javascript
GET /admin/affiliates/{id}
```

### Task 3.4: Conversions Tab (Admin View)
- [ ] Table showing all conversions for this affiliate:
  - Order ID/Number (link to order)
  - Referred User (name, email)
  - Order Amount (box price)
  - Commission Amount
  - Status
  - Date
- [ ] Pagination

**API Call:**
```javascript
GET /admin/affiliates/{id}/conversions
```

### Task 3.5: Payouts Tab (Admin View)
- [ ] Table showing payout history:
  - Amount
  - Notes/Reference
  - Paid By (admin name)
  - Date
- [ ] Pagination

**API Call:**
```javascript
GET /admin/affiliates/{id}/payouts
```

### Task 3.6: Record Payout Modal
- [ ] Show current pending earnings
- [ ] Form:
  - Amount (required, max = pending earnings)
  - Notes (optional - transfer reference, etc.)
- [ ] Submit calls `POST /admin/affiliates/{id}/record-payout`
- [ ] Show success message with new pending balance

### Task 3.7: Edit Affiliate Page (`/app/admin/affiliates/:id/edit`)
- [ ] Create `AdminAffiliateEdit` component
- [ ] Form with:
  - Status (dropdown: active/pending/suspended)
  - Commission Type (percentage/fixed)
  - Commission Value
  - Bank Details:
    - Beneficiary Name
    - Bank Name
    - Account Number
- [ ] Save button calls `PUT /admin/affiliates/{id}`
- [ ] Cancel button returns to view page

### Task 3.8: Delete Affiliate
- [ ] Confirmation modal: "Are you sure you want to delete this affiliate?"
- [ ] On confirm, call `DELETE /admin/affiliates/{id}`
- [ ] Redirect to list

---

## Phase 4: Navigation Updates

### Task 4.1: User Navigation
- [ ] If `user.is_affiliate === true`, show "Affiliate Portal" link in user menu
- [ ] Links to `/app/affiliate/`

### Task 4.2: Admin Navigation
- [ ] Add "Affiliates" link to admin sidebar
- [ ] Links to `/app/admin/affiliates`

---

## API Endpoints Summary

### Public
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/affiliate/validate/{code}` | Validate affiliate code |

### Authenticated User
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/affiliate/become` | Become an affiliate |
| GET | `/affiliate/dashboard` | Get dashboard stats |
| GET | `/affiliate/referrals` | List referred users |
| GET | `/affiliate/conversions` | List conversions/sales |
| GET | `/affiliate/payouts` | List payout history |
| PUT | `/affiliate/profile` | Update bank details |

### Admin
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/affiliates` | List all affiliates |
| POST | `/admin/affiliates` | Create affiliate from user |
| GET | `/admin/affiliates/{id}` | View affiliate |
| PUT | `/admin/affiliates/{id}` | Update affiliate |
| DELETE | `/admin/affiliates/{id}` | Delete affiliate |
| GET | `/admin/affiliates/{id}/conversions` | List conversions |
| GET | `/admin/affiliates/{id}/payouts` | List payouts |
| POST | `/admin/affiliates/{id}/record-payout` | Record bank transfer |

---

## Component Structure

```
src/
  pages/app/
    affiliate/
      AffiliateDashboard.tsx       # Main affiliate portal
    admin/
      affiliates/
        AdminAffiliateList.tsx     # List all affiliates
        AdminAffiliateView.tsx     # View single affiliate
        AdminAffiliateEdit.tsx     # Edit affiliate
  components/
    affiliate/
      AffiliateStatsCards.tsx      # Reusable stats display
      ReferralsTable.tsx           # Referrals list table
      ConversionsTable.tsx         # Conversions list table
      PayoutsTable.tsx             # Payouts list table
      BankDetailsForm.tsx          # Bank details form
      BecomeAffiliateModal.tsx     # Modal for existing users
      BecomeAffiliateBanner.tsx    # Dashboard banner CTA
      RecordPayoutModal.tsx        # Admin payout modal
      CreateAffiliateModal.tsx     # Admin create modal
```

---

## Implementation Priority

**MVP (Start Here):**
1. Referral code cookie handling (Task 2.3)
2. Become affiliate for existing users (Task 2.2)
3. Affiliate dashboard with stats (Task 1.1)
4. Admin affiliates list (Task 3.1)

**Phase 2:**
5. Referrals/Conversions/Payouts tabs
6. Admin view/edit pages
7. Record payout functionality

**Phase 3:**
8. Registration page update (Task 2.1)
9. Polish and edge cases

---

## Notes

- Use existing UI components (tables, forms, modals, badges)
- Follow existing admin panel patterns for consistency
- All monetary values should be formatted as currency
- Affiliate codes should have copy-to-clipboard functionality
- Status badges: green=active, yellow=pending, red=suspended
