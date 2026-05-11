<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-store shipping + tax breakdown for assisted/store PRs.
 *
 * When a PR contains items from multiple US stores, Velonie checks
 * out at each store separately (each has its own shipping cost +
 * sales tax). The admin detail page groups items by their
 * product_url domain so she can enter shipping/tax per store, and
 * we persist the breakdown here so it survives reloads.
 *
 * Shape: { "amazon.com": { "shipping": 5.00, "tax": 3.20 }, ... }
 *
 * createQuote sums across this column when present; falls back to
 * the PR-level shipping_cost / sales_tax inputs in the request body
 * for legacy PRs.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->json('store_costs')->nullable()->after('sales_tax');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('store_costs');
        });
    }
};
