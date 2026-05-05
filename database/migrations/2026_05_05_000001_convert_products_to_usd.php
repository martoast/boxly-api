<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-shot data migration: convert existing product prices from
 * MXN-with-baked-in-markup to source-store USD.
 *
 * The Chrome extension was setting:
 *   cost_cents  = round(priceUsd * fxRate * 100)        // MXN cents
 *   price_cents = round(cost_cents * (1 + markup/100))   // MXN cents
 *
 * Customers will now see the source-store USD price directly. We
 * recover that price by dividing cost_cents by the rate the extension
 * used at capture time (~17.5). Velonie can fix any rows that look off
 * after the migration via the product edit page.
 *
 * cost_cents and markup_percent are no longer part of the new pricing
 * model, but we leave the columns in place to avoid breaking the model
 * fillable / cast contract — they just get nulled out for migrated
 * rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $assumedRate = (float) config('services.exchange_rate.usd_to_mxn', 17.5);

        $migrated = 0;
        DB::table('products')
            ->where('cost_cents', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$migrated, $assumedRate) {
                foreach ($products as $p) {
                    $usdCents = (int) round($p->cost_cents / $assumedRate);
                    if ($usdCents <= 0) continue;
                    DB::table('products')
                        ->where('id', $p->id)
                        ->update([
                            'price_cents'    => $usdCents,
                            'cost_cents'     => null,
                            'markup_percent' => null,
                        ]);
                    $migrated++;
                }
            });

        Log::info("USD price migration complete — {$migrated} products converted (assumed rate {$assumedRate})");
    }

    public function down(): void
    {
        // No safe inverse — the original MXN values are lost. The migration
        // is forward-only by design.
    }
};
