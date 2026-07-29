<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Split the single Stripe War Chest account into the two real accounts:
     * Stripe US (the main account in .env, where platform invoices are paid)
     * and Stripe MX. Both are manually maintained — balances are whatever the
     * admin types in. Order: Stripe US, Stripe MX, HSBC, NU.
     */
    public function up(): void
    {
        $now = now();

        // Rename the seeded "Stripe" row — it keeps its balance and ledger.
        DB::table('war_chest_accounts')
            ->where('name', 'Stripe')
            ->update(['name' => 'Stripe US', 'sort_order' => 1, 'updated_at' => $now]);

        // Idempotent — don't double-insert if this already ran.
        if (! DB::table('war_chest_accounts')->where('name', 'Stripe MX')->exists()) {
            DB::table('war_chest_accounts')->insert([
                'name' => 'Stripe MX',
                'current_balance' => 0,
                'target_amount' => 0,
                'currency' => 'mxn',
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('war_chest_accounts')->where('name', 'HSBC')->update(['sort_order' => 3, 'updated_at' => $now]);
        DB::table('war_chest_accounts')->where('name', 'NU')->update(['sort_order' => 4, 'updated_at' => $now]);
    }

    /**
     * Reverse the migrations. Stripe MX is only removed when it has no ledger
     * history, so a rollback can never destroy recorded movements.
     */
    public function down(): void
    {
        $now = now();

        $mx = DB::table('war_chest_accounts')->where('name', 'Stripe MX')->first();
        if ($mx && ! DB::table('war_chest_transactions')->where('account_id', $mx->id)->exists()) {
            DB::table('war_chest_accounts')->where('id', $mx->id)->delete();
        }

        DB::table('war_chest_accounts')
            ->where('name', 'Stripe US')
            ->update(['name' => 'Stripe', 'sort_order' => 1, 'updated_at' => $now]);

        DB::table('war_chest_accounts')->where('name', 'HSBC')->update(['sort_order' => 2, 'updated_at' => $now]);
        DB::table('war_chest_accounts')->where('name', 'NU')->update(['sort_order' => 3, 'updated_at' => $now]);
    }
};
