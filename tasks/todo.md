# Admin Order Boxes API

## Task
Create admin API endpoints to view and search order boxes for the frontend admin panel.

## Plan
- [x] Create `AdminOrderBoxController.php` with view-only endpoints
- [x] Add routes to `routes/api.php` under admin prefix
- [x] Verify syntax

## Review

Created new admin endpoints for viewing order boxes:

**Endpoints:**
- `GET /admin/boxes` - List all boxes (paginated, latest first by default)
- `GET /admin/boxes/{box}` - Get single box with order and user details

**Features:**
- Default sort: `created_at desc` (latest boxes first)
- Pagination with meta info (current_page, last_page, per_page, total)
- Search across: guia_number, box_name, order_number, tracking_number, user name/email
- Filters: box_size, has_gia, date range, order_status
- Sorting: created_at, box_price, box_size, box_name

**Files Created/Modified:**
- `app/Http/Controllers/AdminOrderBoxController.php` (new)
- `routes/api.php` (added routes)
