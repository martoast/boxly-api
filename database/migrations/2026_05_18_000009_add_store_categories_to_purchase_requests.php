<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-store category selections for in-person PRs. Replaces the flat
 * PR<->categories pivot. Stored as a JSON map of store_id → [category_id...]
 * so admin can see "at Nike the customer wants Sneakers + Sportswear; at
 * Coach they want Bags" instead of one giant unscoped list. JSON instead
 * of a 3-way pivot because we never query categories — only render them
 * inside a single PR — and the JSON column keeps the schema simple.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->json('store_categories')->nullable()->after('in_person_store_count');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('store_categories');
        });
    }
};
