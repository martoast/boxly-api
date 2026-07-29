<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add the Boxly USA LLC US bank account to the War Chest. Manually
     * maintained like every other account. Slots in after the Stripe pair so
     * the list reads: Stripe US, Stripe MX, US Bank Boxly LLC, HSBC, NU.
     *
     * currency stays 'mxn' like the rest — the UI ignores the field and sums
     * every balance into one "Progreso General" total, so mixing in a 'usd'
     * row would silently make that total meaningless.
     */
    public function up(): void
    {
        $now = now();

        // Idempotent — don't double-insert if this already ran.
        if (! DB::table('war_chest_accounts')->where('name', 'US Bank Boxly LLC')->exists()) {
            DB::table('war_chest_accounts')->insert([
                'name' => 'US Bank Boxly LLC',
                'current_balance' => 0,
                'target_amount' => 0,
                'currency' => 'mxn',
                'sort_order' => 3,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('war_chest_accounts')->where('name', 'HSBC')->update(['sort_order' => 4, 'updated_at' => $now]);
        DB::table('war_chest_accounts')->where('name', 'NU')->update(['sort_order' => 5, 'updated_at' => $now]);
    }

    /**
     * Reverse the migrations. The account is only removed when it has no ledger
     * history, so a rollback can never destroy recorded movements.
     */
    public function down(): void
    {
        $now = now();

        $acct = DB::table('war_chest_accounts')->where('name', 'US Bank Boxly LLC')->first();
        if ($acct && ! DB::table('war_chest_transactions')->where('account_id', $acct->id)->exists()) {
            DB::table('war_chest_accounts')->where('id', $acct->id)->delete();
        }

        DB::table('war_chest_accounts')->where('name', 'HSBC')->update(['sort_order' => 3, 'updated_at' => $now]);
        DB::table('war_chest_accounts')->where('name', 'NU')->update(['sort_order' => 4, 'updated_at' => $now]);
    }
};
