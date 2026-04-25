<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Snapshots at purchase time (so historical orders are stable if product changes)
            $table->string('name_snapshot');
            $table->unsignedInteger('price_cents_snapshot');
            $table->decimal('weight_kg_snapshot', 6, 2);
            $table->string('image_url_snapshot')->nullable();

            $table->unsignedInteger('quantity');

            // Stripe payment tracking — which Checkout session paid for this item
            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Per-item fulfillment
            $table->enum('status', ['pending_payment', 'ordered', 'received', 'packed', 'shipped'])->default('pending_payment');
            $table->timestamp('received_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('stripe_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_items');
    }
};
