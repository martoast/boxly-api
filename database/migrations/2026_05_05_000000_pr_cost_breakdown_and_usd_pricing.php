<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New per-item cost breakdown for store-source Purchase Requests.
 *
 * Pricing is now genuinely transparent to the customer: catalog prices
 * are the source-store USD price (no markup baked in). After the
 * customer creates a PR, Velonie reviews each item, marks availability,
 * and enters the actual taxes + shipping the source store charges her
 * at checkout, plus the Boxly commission %. The system computes per-
 * item totals in USD, snapshots the live USD→MXN rate, and generates a
 * Stripe invoice in MXN.
 *
 * Existing `price` column on purchase_request_items stays — it captures
 * the customer-visible USD line price at PR-creation time so we have
 * the original quote on file even after Velonie's adjustments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->decimal('tax_usd', 10, 2)->nullable()->after('price');
            $table->decimal('shipping_usd', 10, 2)->nullable()->after('tax_usd');
            $table->decimal('commission_percent', 5, 2)->nullable()->after('shipping_usd');
            $table->decimal('final_usd', 10, 2)->nullable()->after('commission_percent');
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            // FX rate snapshot at the moment the Stripe invoice was generated.
            // Pinning it here lets us recompute / audit the math later even if
            // the live rate has drifted.
            $table->decimal('fx_rate_used', 8, 4)->nullable()->after('total_amount');
            $table->decimal('total_usd', 10, 2)->nullable()->after('fx_rate_used');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->dropColumn(['tax_usd', 'shipping_usd', 'commission_percent', 'final_usd']);
        });
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn(['fx_rate_used', 'total_usd']);
        });
    }
};
