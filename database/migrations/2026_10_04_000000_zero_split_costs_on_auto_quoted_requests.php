<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Automatic quotes wrote each store's checkout TOTAL (shipping and tax included) to
 * items_total AND the same shipping and tax to shipping_cost / sales_tax, so the admin
 * dashboard counted them twice (PR-26-AGYER: cost $91.06 vs the real $78.03). Only
 * automatic quotes set store_costs; for those, items_total is already the whole cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('purchase_requests')
            ->whereNotNull('store_costs')
            ->whereNotNull('stripe_invoice_id')
            ->update(['shipping_cost' => 0, 'sales_tax' => 0]);
    }

    public function down(): void
    {
        // The split stays in store_costs; nothing to restore.
    }
};
