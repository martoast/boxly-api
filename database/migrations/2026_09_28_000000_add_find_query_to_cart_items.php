<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Search to cart (2026-09-28): a cart line the engine must FIND with the store's own search (a web result from an
// outside seller of a brand we carry). `find_query` is what to search for; product_url is the store's site until
// the engine reports the product page it found, which then replaces it and clears find_query.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->string('find_query', 300)->nullable()->after('saved_id');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('find_query');
        });
    }
};
