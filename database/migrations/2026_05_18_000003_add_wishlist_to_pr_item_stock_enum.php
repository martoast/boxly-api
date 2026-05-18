<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 'wishlist' is the pre-trip state for items the customer asked us to look
 * for at Las Americas. They're excluded from billing the same way
 * 'unavailable' is. After the trip, admin either:
 *   - flips the wish to 'available' with the actual price found at the mall, or
 *   - flips it to 'unavailable' (we couldn't find it), or
 *   - leaves the wish + adds new 'available' items via addItem for surprise finds.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE purchase_request_items MODIFY COLUMN stock_status "
            . "ENUM('unverified', 'available', 'unavailable', 'wishlist') NOT NULL DEFAULT 'unverified'"
        );
    }

    public function down(): void
    {
        DB::table('purchase_request_items')
            ->where('stock_status', 'wishlist')
            ->update(['stock_status' => 'unverified']);

        DB::statement(
            "ALTER TABLE purchase_request_items MODIFY COLUMN stock_status "
            . "ENUM('unverified', 'available', 'unavailable') NOT NULL DEFAULT 'unverified'"
        );
    }
};
