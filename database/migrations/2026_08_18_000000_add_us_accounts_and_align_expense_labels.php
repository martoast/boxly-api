<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Line the expense "paid from" labels up with the real War Chest accounts.
 *
 * The 2026_07_29 migration split Stripe into "Stripe US" and "Stripe MX", but
 * the expense form was never told: it kept offering a single ambiguous
 * "Stripe" chip and had no way at all to record something paid from Stripe MX.
 * Adding Boxly USA's US bank account is what surfaced it.
 *
 * After this: chips and War Chest accounts are the same five names, and the
 * `payment_method` routing key on each account matches the label an expense
 * stores — which is what that column was added for.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Existing expenses said "Stripe" back when there was only one. They
        // were all the US account, so move them rather than stranding them
        // under a label the form no longer offers.
        DB::table('business_expenses')
            ->where('payment_method', 'Stripe')
            ->update(['payment_method' => 'Stripe US', 'updated_at' => $now]);

        // Boxly USA LLC's bank. USD, unlike the Mexican accounts.
        if (! DB::table('war_chest_accounts')->where('name', 'US Bank')->exists()) {
            DB::table('war_chest_accounts')->insert([
                'name'            => 'US Bank',
                'payment_method'  => 'US Bank',
                'current_balance' => 0,
                'target_amount'   => 0,
                'currency'        => 'usd',
                'sort_order'      => 5,
                'is_active'       => true,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        // Routing keys still held the pre-split names.
        DB::table('war_chest_accounts')->where('name', 'Stripe US')
            ->update(['payment_method' => 'Stripe US', 'updated_at' => $now]);
        DB::table('war_chest_accounts')->where('name', 'Stripe MX')
            ->update(['payment_method' => 'Stripe MX', 'updated_at' => $now]);
    }

    /**
     * Reversible except for the US Bank row, which is only dropped when it has
     * no ledger history — same rule the Stripe split used, so a rollback can
     * never destroy recorded movements.
     */
    public function down(): void
    {
        $now = now();

        DB::table('business_expenses')
            ->where('payment_method', 'Stripe US')
            ->update(['payment_method' => 'Stripe', 'updated_at' => $now]);

        // Expenses paid from accounts that didn't exist before this migration
        // have no valid old label — null is honest, a wrong bucket isn't.
        DB::table('business_expenses')
            ->whereIn('payment_method', ['Stripe MX', 'US Bank'])
            ->update(['payment_method' => null, 'updated_at' => $now]);

        DB::table('war_chest_accounts')->where('name', 'Stripe US')
            ->update(['payment_method' => 'Stripe', 'updated_at' => $now]);
        DB::table('war_chest_accounts')->where('name', 'Stripe MX')
            ->update(['payment_method' => null, 'updated_at' => $now]);

        $usBank = DB::table('war_chest_accounts')->where('name', 'US Bank')->first();
        if ($usBank && ! DB::table('war_chest_transactions')->where('account_id', $usBank->id)->exists()) {
            DB::table('war_chest_accounts')->where('id', $usBank->id)->delete();
        }
    }
};
