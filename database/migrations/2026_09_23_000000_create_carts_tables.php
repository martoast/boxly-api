<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Boxly cart: a real, persisted list of products a customer wants us to buy,
 * across stores, finalized into ONE purchase request.
 *
 * ONE OPEN CART PER USER, enforced by the database the same way
 * live_shopping_sessions does it: `active_slot` is 1 while the cart is open and
 * nulled when it is finalized/abandoned, and UNIQUE(user_id, active_slot) does
 * the work because NULLs never collide in a unique index (MySQL and SQLite).
 *
 * Items dedupe on (cart, product url, normalized variants). A url is TEXT, which
 * MySQL cannot index in full, so the unique index uses its sha256 instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['open', 'finalized', 'abandoned'])->default('open');
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->foreignId('conversation_id')->nullable()
                ->constrained('conversations')->nullOnDelete();
            $table->foreignId('purchase_request_id')->nullable()
                ->constrained('purchase_requests')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'active_slot']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->string('store_id', 40);
            $table->string('store_name')->nullable();
            $table->text('product_url');
            $table->char('product_url_hash', 64);
            $table->string('title', 500);
            $table->text('image_url')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->json('variants');
            $table->string('variants_key', 255)->default('');
            $table->enum('source', ['chat', 'live', 'extension']);
            $table->string('saved_id')->nullable();
            $table->enum('sync_status', ['pending', 'syncing', 'in_store_cart', 'unavailable', 'failed'])->default('pending');
            $table->string('sync_note')->nullable();
            $table->timestamps();

            $table->unique(['cart_id', 'product_url_hash', 'variants_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
