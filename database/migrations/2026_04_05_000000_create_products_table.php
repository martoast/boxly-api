<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('sku')->nullable();
            $table->string('source_url')->nullable(); // admin-only

            $table->unsignedInteger('price_cents'); // MXN

            // Physical (per unit) — REQUIRED, drives box estimation
            $table->decimal('weight_kg', 6, 2);
            $table->decimal('length_cm', 6, 1);
            $table->decimal('width_cm', 6, 1);
            $table->decimal('height_cm', 6, 1);

            $table->unsignedInteger('stock')->default(0);
            $table->enum('status', ['draft', 'active', 'inactive', 'sold_out'])->default('draft');
            $table->timestamp('available_until')->nullable();
            $table->string('category')->nullable();
            $table->json('images')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('category');
            $table->index('available_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
