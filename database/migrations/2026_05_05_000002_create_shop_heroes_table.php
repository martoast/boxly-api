<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable hero banner for the /shop storefront landing.
 *
 * One active record at a time (history is kept by toggling is_active).
 * Lets the shopping manager rotate campaigns — "Athletic Wear",
 * "Stanley Drinkware", "Alo Yoga New Drop", etc. — without code
 * changes. CTA link points at any /shop?... filter URL so the hero
 * deep-links into a curated listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_heroes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('image_path')->nullable();   // Spaces path
            $table->string('image_url')->nullable();    // CDN URL
            $table->string('cta_label')->default('Comprar');
            $table->string('cta_link', 1000);           // e.g. /shop?category_id=5
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_heroes');
    }
};
