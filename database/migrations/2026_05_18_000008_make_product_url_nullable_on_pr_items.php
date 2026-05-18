<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wishlist items on in-person PRs often won't have a URL — the customer is
 * just describing what they want us to look for at the mall ("Nike Air Max
 * 90 black, size 10"). Relax the not-null so we don't have to store dummy
 * strings. Existing online/store flows still require a URL at the
 * validation layer, so behavior there is unchanged.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->string('product_url', 1000)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Backfill any wishlist nulls before reapplying the constraint so the
        // rollback doesn't blow up against legitimate in-person data.
        \Illuminate\Support\Facades\DB::table('purchase_request_items')
            ->whereNull('product_url')
            ->update(['product_url' => '']);

        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->string('product_url', 1000)->nullable(false)->change();
        });
    }
};
