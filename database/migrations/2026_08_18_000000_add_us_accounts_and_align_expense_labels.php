<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Point the expense "paid from" labels at the War Chest accounts that actually
 * exist: Stripe US, Stripe MX, US Bank Boxly LLC, HSBC, NU.
 *
 * The 2026_07_29 migrations split Stripe into US/MX and added the US bank, but
 * the expense form was never told — it still offered one ambiguous "Stripe"
 * chip, and had no way at all to record something paid from Stripe MX or the
 * US account.
 *
 * Data only. The accounts already exist and `war_chest_accounts` has no
 * routing column any more (2026_07_29_100000 dropped `payment_method` on
 * purpose — the War Chest is pure CRUD and nothing matches an expense to an
 * account automatically). The label an expense stores is exactly the account
 * name, which is all the link there is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Everything recorded as "Stripe" predates the split, so it was paid
        // from the US account — the only Stripe account that existed then.
        DB::table('business_expenses')
            ->where('payment_method', 'Stripe')
            ->update(['payment_method' => 'Stripe US', 'updated_at' => $now]);

        // A first cut of this shipped the chip as a bare "US Bank", which
        // matches no account. Move any expense saved in that window onto the
        // real account name.
        DB::table('business_expenses')
            ->where('payment_method', 'US Bank')
            ->update(['payment_method' => 'US Bank Boxly LLC', 'updated_at' => $now]);
    }

    public function down(): void
    {
        DB::table('business_expenses')
            ->where('payment_method', 'Stripe US')
            ->update(['payment_method' => 'Stripe', 'updated_at' => now()]);

        // Stripe MX and the US bank had no label before this, and guessing a
        // wrong bucket is worse than admitting we don't know.
        DB::table('business_expenses')
            ->whereIn('payment_method', ['Stripe MX', 'US Bank Boxly LLC'])
            ->update(['payment_method' => null, 'updated_at' => now()]);
    }
};
