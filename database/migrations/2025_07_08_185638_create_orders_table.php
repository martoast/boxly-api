<?php
// database/migrations/2025_07_08_185638_create_orders_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Order details
            $table->string('order_number')->unique(); // System-generated like PC-2025-000001
            
            // Status tracking
            $table->enum('status', [
                'collecting',           // Customer adding items
                'awaiting_packages',    // Order finalized, packages in transit
                'packages_complete',    // All packages received and measured
                'shipped',              // Consolidated package sent to Mexico
                'delivered'             // Package delivered to customer
            ])->default('collecting');
            
            // Box information (selected at checkout)
            $table->enum('box_size', ['extra-small', 'small', 'medium', 'large', 'extra-large']);
            $table->decimal('box_price', 10, 2)->comment('Price paid for the box in USD');
            $table->decimal('declared_value', 10, 2)->comment('Total declared value for IVA calculation');
            $table->decimal('iva_amount', 10, 2)->comment('16% IVA on declared value');
            $table->boolean('is_rural')->default(false);
            $table->decimal('rural_surcharge', 10, 2)->nullable();
            
            // Weight and measurements (filled after all packages arrive)
            $table->decimal('total_weight', 8, 2)->nullable()->comment('Total weight in kg');
            
            // Stripe Payment Information (from initial checkout)
            $table->string('stripe_product_id')->comment('Stripe product ID for the box');
            $table->string('stripe_price_id')->comment('Stripe price ID used');
            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->decimal('amount_paid', 10, 2)->comment('Total amount paid including rural surcharge');
            $table->string('currency', 3)->default('usd');
            $table->timestamp('paid_at')->nullable();
            
            // Shipping information
            $table->string('tracking_number')->nullable()->comment('Mexican carrier tracking');
            $table->json('delivery_address')->nullable();
            $table->date('estimated_delivery_date')->nullable();
            $table->date('actual_delivery_date')->nullable();
            
            // Important dates
            $table->timestamp('completed_at')->nullable()->comment('When marked ready for consolidation');
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('status');
            $table->index('order_number');
            $table->index(['user_id', 'status']);
            $table->index('stripe_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};