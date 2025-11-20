<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. The main request ticket
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('request_number')->unique(); // PR-2025-XXXX
            
            $table->enum('status', [
                'pending_review', // User submitted, waiting for admin
                'quoted',         // Admin added costs, invoice sent
                'paid',           // User paid invoice
                'purchased',      // Admin bought items (converted to OrderItems)
                'rejected',       // Admin rejected (e.g. prohibited items)
                'cancelled'       // User or Admin cancelled
            ])->default('pending_review');

            // Financials
            $table->decimal('items_total', 10, 2)->nullable(); // Sum of items
            $table->decimal('shipping_cost', 10, 2)->nullable(); // Shipping to warehouse
            $table->decimal('sales_tax', 10, 2)->nullable(); // US Sales Tax
            $table->decimal('processing_fee', 10, 2)->nullable(); // The 8% Markup
            $table->decimal('total_amount', 10, 2)->nullable(); // Grand Total invoiced
            $table->string('currency', 3)->default('usd'); // Purchasing usually happens in USD

            // Stripe Integration
            $table->string('stripe_invoice_id')->nullable();
            $table->string('payment_link')->nullable();
            $table->timestamp('quote_sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('purchased_at')->nullable();

            $table->text('admin_notes')->nullable(); // For rejection reasons or internal notes
            $table->timestamps();
            
            $table->index('status');
            $table->index('request_number');
        });

        // 2. The items within the request
        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->onDelete('cascade');
            
            $table->string('product_name');
            $table->string('product_url', 1000);
            $table->string('product_image_url', 1000)->nullable();
            $table->decimal('price', 10, 2)->nullable(); // Est. price per unit
            $table->integer('quantity')->default(1);
            $table->json('options')->nullable(); // Size, Color, etc.
            $table->text('notes')->nullable(); // User notes for this item

            $table->timestamps();
        });

        // 3. Modify existing order_items to link back
        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('is_assisted_purchase')->default(false)->after('order_id');
            $table->foreignId('purchase_request_item_id')->nullable()->after('is_assisted_purchase')
                  ->constrained('purchase_request_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['purchase_request_item_id']);
            $table->dropColumn(['is_assisted_purchase', 'purchase_request_item_id']);
        });
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
    }
};