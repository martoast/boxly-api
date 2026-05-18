<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the flat PR<->categories pivot — superseded by the per-store
 * store_categories JSON map on purchase_requests. No data migration:
 * the table never held any rows (in-person flow shipped today and
 * was redesigned before any customer used the flat categories path).
 *
 * dropIfExists keeps this safe to rerun on environments where the
 * table was never created.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('purchase_request_categories');
    }

    public function down(): void
    {
        Schema::create('purchase_request_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['purchase_request_id', 'category_id']);
        });
    }
};
