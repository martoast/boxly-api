<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The store's tracking number, alongside the order number Velonie already records.
 *
 * The order number proves the purchase happened; the tracking number is the only
 * thing that answers "where is it right now" — which is the question actually
 * being asked of the Compras board.
 *
 * Nullable, no default, no backfill: every existing row keeps working and the
 * deploy is safe in either order (app first or api first).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchased_products', function (Blueprint $table) {
            $table->string('tracking_number')->nullable()->after('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('purchased_products', function (Blueprint $table) {
            $table->dropColumn('tracking_number');
        });
    }
};
