<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Variant axes (any combination — size only, color only, or both)
            $table->string('size')->nullable();
            $table->string('color')->nullable();

            // Underlying retailer variant id (e.g. Shopify variant id) — used to map
            // stock check responses back to the right variant
            $table->string('shopify_variant_id')->nullable();

            // Optional per-variant price override (rare; defaults to product.price_cents)
            $table->unsignedInteger('price_cents')->nullable();

            // Stock check (per variant)
            $table->enum('stock_check_status', ['unknown', 'in_stock', 'out_of_stock'])->default('unknown');
            $table->timestamp('last_stock_check_at')->nullable();
            $table->text('last_stock_check_response')->nullable();

            $table->unsignedSmallInteger('display_order')->default(0);

            $table->timestamps();

            $table->index('product_id');
            $table->index('stock_check_status');
            $table->index('shopify_variant_id');
            $table->unique(['product_id', 'size', 'color']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
