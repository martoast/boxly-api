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
            
            // Order details
            $table->string('order_name'); // User-given name like "Christmas Shopping"
            $table->string('order_number')->unique(); // System-generated like PC-2025-000001
            
            // Status tracking
            $table->enum('status', [
                'collecting',           // Customer adding items
                'awaiting_packages',    // Order finalized, packages in transit
                'packages_complete',    // All packages received and measured
                'quote_sent',          // Admin sent consolidation quote
                'paid',                // Customer approved and paid
                'shipped',             // Consolidated package sent to Mexico
                'delivered'            // Package delivered to customer
            ])->default('collecting');
            
            // Weight and measurements (filled after all packages arrive)
            $table->decimal('total_weight', 8, 2)->nullable()->comment('Total weight in kg');
            $table->enum('recommended_box_size', ['small', 'medium', 'large', 'xl'])->nullable();
            
            // Stripe Invoice (created when quote is sent)
            $table->string('stripe_invoice_id')->nullable()->unique();
            $table->string('stripe_invoice_url')->nullable(); // Hosted invoice URL
            
            // Payment information (filled after payment)
            $table->decimal('amount_paid', 10, 2)->nullable();
            $table->string('currency', 3)->default('mxn');
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            
            // Shipping information
            $table->string('tracking_number')->nullable()->comment('Mexican carrier tracking');
            $table->json('delivery_address')->nullable();
            $table->boolean('is_rural')->default(false);
            $table->date('estimated_delivery_date')->nullable();
            $table->date('actual_delivery_date')->nullable();
            
            // Important dates
            $table->timestamp('completed_at')->nullable()->comment('When marked ready for consolidation');
            $table->timestamp('quote_sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('status');
            $table->index('order_number');
            $table->index(['user_id', 'status']);
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};