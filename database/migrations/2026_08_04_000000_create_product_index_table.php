<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The product index — what the Shopper panel already resolves, kept.
 *
 * Every panel open runs a shopping search, a vision curation pass and a price
 * verification chain, lands on an answer in 17–26 seconds, caches it for 15
 * MINUTES and then throws it away. We were generating exactly the data an index
 * needs and deleting it four times an hour.
 *
 * This is where it lives now. The second shopper on a product pays nothing for
 * what the first one waited for, and over time this becomes the small, warm
 * catalogue described in app/tasks/product-index.md — a few thousand products
 * Mexican customers actually buy, rather than Phia's 250 million.
 *
 * A CACHE, never a source of truth: a miss falls through to the live path, and
 * `resolved_at` exists so a stale price can never quietly wear a "verified"
 * badge. Staleness is the new way an index lets you lie, and `verified` is the
 * one thing that panel sells.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_index', function (Blueprint $table) {
            $table->id();

            /**
             * How a product is recognised across stores. First that exists:
             *   gtin → brand+mpn/sku → slug(brand + title + variant)
             * Unique, because the whole point is that two shoppers on two
             * different retailers land on the same row.
             */
            $table->string('canonical_key', 191)->unique();

            // gtin / sku / mpn / epid, whatever the page and eBay gave us. Kept
            // raw so a better key can be derived later without re-resolving.
            $table->json('identifiers')->nullable();

            $table->string('title', 300)->nullable();
            $table->string('brand', 120)->nullable();
            $table->string('variant', 120)->nullable();
            $table->text('image')->nullable();
            $table->string('store', 120)->nullable();

            // The resolved half: listings, offers, query, us_price.
            $table->longText('payload');

            // When the PRICES in payload were true. Not updated_at — that moves
            // for reasons that have nothing to do with price.
            $table->timestamp('resolved_at')->index();

            // Cheap popularity signal: which keys are worth refreshing first
            // when stage 4 (scheduled refresh) lands.
            $table->unsignedInteger('hits')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_index');
    }
};
