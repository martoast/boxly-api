<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot capturing the customer's stated category interests on an
 * in-person PR ("Gym Clothes", "Sneakers"). Helps admin scope the
 * shopping trip before showing up at the mall.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_request_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['purchase_request_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_categories');
    }
};
