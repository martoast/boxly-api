<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the order_boxes table to support multiple boxes per order.
     * Each order can now have multiple boxes with different sizes/prices.
     */
    public function up(): void
    {
        Schema::create('order_boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');

            // Box information from Stripe
            $table->string('stripe_price_id');
            $table->string('stripe_product_id');
            $table->enum('box_size', ['extra-small', 'small', 'medium', 'large', 'extra-large']);
            $table->string('box_name'); // e.g., "Medium Box"
            $table->decimal('box_price', 10, 2);
            $table->string('currency', 3)->default('mxn');

            // Quantity - in case same box size is selected multiple times
            $table->unsignedInteger('quantity')->default(1);

            $table->timestamps();

            // Indexes
            $table->index('order_id');
            $table->index('box_size');
            $table->index('stripe_price_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_boxes');
    }
};
