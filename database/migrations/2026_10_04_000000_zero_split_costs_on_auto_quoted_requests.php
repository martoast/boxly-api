<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Automatic quotes wrote each store's checkout TOTAL (shipping and tax included) to
 * items_total AND the same shipping and tax to shipping_cost / sales_tax, so the admin
 * dashboard counted them twice (PR-26-AGYER: cost $91.06 vs the real $78.03).
 * Only a request whose items_total IS the sum of its store_costs totals is fixed:
 * older requests kept merchandise and tax apart, and their split is real.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('purchase_requests')
            ->whereNotNull('store_costs')
            ->whereNotNull('stripe_invoice_id')
            ->where(fn ($q) => $q->where('shipping_cost', '>', 0)->orWhere('sales_tax', '>', 0))
            ->orderBy('id')
            ->get(['id', 'items_total', 'store_costs'])
            ->each(function ($pr) {
                $stores = collect((array) json_decode($pr->store_costs, true));
                if ($stores->isEmpty() || $stores->contains(fn ($s) => ! is_array($s) || ! isset($s['total']))) {
                    return;
                }
                if (abs($stores->sum(fn ($s) => (float) $s['total']) - (float) $pr->items_total) > 0.01) {
                    return;
                }
                DB::table('purchase_requests')->where('id', $pr->id)->update(['shipping_cost' => 0, 'sales_tax' => 0]);
            });
    }

    public function down(): void
    {
        // The split stays in store_costs; nothing to restore.
    }
};
