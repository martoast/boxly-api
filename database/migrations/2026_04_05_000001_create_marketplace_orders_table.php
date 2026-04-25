<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('status', [
                'collecting',                 // accumulating items, customer can keep buying
                'ready_to_ship',              // customer requested shipment
                'packing',                    // admin packing, some items received
                'awaiting_shipping_payment',  // box assigned, invoice sent
                'shipping_paid',              // shipping invoice paid
                'shipped',                    // out the door
                'delivered',                  // received in MX
                'cancelled',
                'refunded',
            ])->default('collecting');

            // Cumulative items payment (sum of all Stripe Checkouts that contributed)
            $table->unsignedInteger('items_subtotal_cents')->default(0);
            $table->timestamp('items_paid_at')->nullable();

            // Box assignment (post-consolidation, by admin)
            $table->enum('box_size', ['XS', 'S', 'M', 'L', 'XL'])->nullable();
            $table->unsignedInteger('box_price_cents')->nullable();
            $table->json('box_summary')->nullable();

            // Shipping payment
            $table->string('shipping_invoice_id')->nullable();
            $table->string('shipping_payment_link')->nullable();
            $table->timestamp('shipping_paid_at')->nullable();

            // Delivery
            $table->json('shipping_address')->nullable();
            $table->string('guia_number')->nullable();
            $table->string('gia_path')->nullable();
            $table->string('gia_url')->nullable();
            $table->date('estimated_delivery_date')->nullable();
            $table->date('actual_delivery_date')->nullable();
            $table->timestamp('shipped_at')->nullable();

            // Customer-requested shipment timestamp
            $table->timestamp('shipment_requested_at')->nullable();

            // Refund
            $table->timestamp('refunded_at')->nullable();
            $table->unsignedInteger('refund_amount_cents')->nullable();
            $table->text('refund_reason')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_orders');
    }
};
