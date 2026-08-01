<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What actually happens after someone installs the shopper extension.
 *
 * Until now we knew installs, version and last-seen — nothing about whether the
 * thing works. COMPASS §7: "you cannot tune a funnel you cannot see", and the
 * North Star Metric (items added to a box from the extension) had no collection
 * path at all. We had just built the converting half of the product and could
 * not tell whether a single person used it.
 *
 * ── What this deliberately does NOT store ───────────────────────────────────
 *
 * No URL. No store name. No product.
 *
 * The Chrome Web Store data disclosure says we do not collect browsing history,
 * and COMPASS §5 lists that as a commitment rather than a preference. A row
 * saying "user 263 opened the panel on aloyoga.com" IS browsing history, however
 * useful it would be. So an event carries only:
 *
 *   kind        — which step of the funnel
 *   localized   — was it a localized storefront (the pages §1 is about)? a
 *                 boolean, not a domain
 *   gap_percent — how big the revealed gap was, for the median in §8
 *
 * That is enough to answer every question in §7 except "which stores work",
 * which the coverage sweep answers without touching a shopper's browsing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopper_extension_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // panel_open  — they opened the panel on a product page
            // gap_shown   — we had a real US-vs-MX comparison to show them
            // box_add     — they put it in their box (the North Star Metric)
            // listing_click — they opened one of the US listings we found
            // autofill    — they used the address autofill at a checkout
            $table->string('kind', 24);

            $table->boolean('localized')->default(false);
            $table->unsignedSmallInteger('gap_percent')->nullable();

            $table->timestamps();

            // Every query in `boxly ext stats` is "this kind, this window".
            $table->index(['kind', 'created_at']);
            $table->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopper_extension_events');
    }
};
