<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C5 — one checkout quote per purchase request × store, taken by the live
 * shopping engine in the customer's own store cart (engine contract:
 * docs/C3_CART_SYNC_CONTRACT.md § C5). Money in integer cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->string('store_id', 64);
            $table->string('store_name')->nullable();
            // pending | running | verified | partial | failed
            $table->string('status', 16)->default('pending');
            $table->foreignId('live_shopping_session_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('attempts')->default(0);
            $table->char('currency', 3)->nullable();
            foreach (['merchandise', 'discounts', 'shipping', 'tax', 'fees', 'total'] as $part) {
                $table->bigInteger("{$part}_cents")->nullable();
            }
            $table->boolean('estimated')->default(false);
            $table->boolean('destination_verified')->default(false);
            $table->string('checkout_stage', 16)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['purchase_request_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_quotes');
    }
};
