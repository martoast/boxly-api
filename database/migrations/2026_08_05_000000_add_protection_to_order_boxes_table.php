<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boxly Protection — an optional per-box add-on covering verified theft, loss
 * or damage, sold alongside the box itself on the same invoice.
 *
 * Lives on the box entry, not the order, because the admin decides box by box:
 * one order can ship a protected XL of electronics next to an unprotected S of
 * clothes.
 *
 * The price is snapshotted the same way box_price is. Stripe's list price will
 * move, and a receipt from six months ago has to keep saying what was actually
 * charged. It is READ live from Stripe at the moment of sale and written here.
 *
 * orders.protection_total is the summed protection for the order.
 * orders.box_price deliberately keeps meaning boxes-only — folding protection
 * into it would make every "total de cajas" label wrong and desync the per-box
 * lines from the total. Order::calculateOrderTotal() adds the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_boxes', function (Blueprint $table) {
            $table->boolean('has_protection')->default(false)->after('quantity');

            // Per-box amount charged. A box entry can carry quantity > 1, and
            // protection is per box — so the entry's cost is price × quantity.
            $table->decimal('protection_price', 10, 2)->nullable()->after('has_protection');

            $table->string('protection_price_id')->nullable()->after('protection_price');
            $table->string('protection_product_id')->nullable()->after('protection_price_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('protection_total', 10, 2)->nullable()->after('box_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_boxes', function (Blueprint $table) {
            $table->dropColumn([
                'has_protection',
                'protection_price',
                'protection_price_id',
                'protection_product_id',
            ]);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('protection_total');
        });
    }
};
