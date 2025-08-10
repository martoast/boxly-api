<?php

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
            
            // Order identifiers
            $table->string('order_number')->unique();
            $table->string('tracking_number')->unique();
            
            // Status tracking - FIXED: Added all statuses including 'collecting'
            $table->enum('status', [
                'collecting',
                'awaiting_packages',
                'packages_complete',
                'processing',
                'quote_sent',
                'paid',
                'shipped',
                'delivered',
                'cancelled'
            ])->default('collecting');
            
            // Box information (nullable - admin selects when preparing quote)
            $table->enum('box_size', ['extra-small', 'small', 'medium', 'large', 'extra-large'])->nullable();
            $table->decimal('box_price', 10, 2)->nullable();
            
            // Customs and tax
            $table->decimal('declared_value', 10, 2)->nullable();
            $table->decimal('iva_amount', 10, 2)->nullable();
            
            // Delivery
            $table->json('delivery_address');
            $table->boolean('is_rural')->default(false);
            $table->decimal('rural_surcharge', 10, 2)->nullable();
            
            // Weight
            $table->decimal('total_weight', 8, 2)->nullable();
            $table->decimal('actual_weight', 8, 2)->nullable();
            
            // Shipping costs
            $table->decimal('shipping_cost', 10, 2)->nullable();
            $table->decimal('handling_fee', 10, 2)->nullable();
            $table->decimal('insurance_fee', 10, 2)->nullable();
            
            // Quote - FIXED: quote_breakdown as JSON
            $table->decimal('quoted_amount', 10, 2)->nullable();
            $table->json('quote_breakdown')->nullable();
            $table->timestamp('quote_sent_at')->nullable();
            $table->timestamp('quote_expires_at')->nullable();
            
            // Payment (Stripe)
            $table->string('stripe_product_id')->nullable();
            $table->string('stripe_price_id')->nullable();
            $table->string('stripe_checkout_session_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('stripe_invoice_id')->nullable();
            $table->string('payment_link')->nullable();
            $table->decimal('amount_paid', 10, 2)->nullable();
            $table->string('currency', 3)->default('mxn');
            $table->timestamp('paid_at')->nullable();
            
            // Delivery dates
            $table->date('estimated_delivery_date')->nullable();
            $table->date('actual_delivery_date')->nullable();
            
            // Timestamps for various stages
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            
            // Additional fields
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('status');
            $table->index('order_number');
            $table->index('tracking_number');
            $table->index(['user_id', 'status']);
            $table->index('stripe_invoice_id');
            $table->index('quote_sent_at');
            $table->index(['status', 'created_at']);
            $table->index('paid_at');
            $table->index('completed_at');
            $table->index('quote_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};