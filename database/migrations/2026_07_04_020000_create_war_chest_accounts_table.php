<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * War Chest = the money held in each account (NU / HSBC / Stripe). Orders
     * paid at a method credit its account; expenses paid with a method debit it.
     * current_balance is admin-editable at any time; target_amount is the goal.
     */
    public function up(): void
    {
        Schema::create('war_chest_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                  // display name (e.g. "Alex Card")
            $table->string('payment_method', 20)->nullable()->unique(); // routing key: NU|HSBC|Stripe
            $table->decimal('current_balance', 12, 2)->default(0);
            $table->decimal('target_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('mxn');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed the three accounts that orders/expenses route to. Balances start
        // at 0 — the admin sets today's real balance from the UI.
        $now = now();
        DB::table('war_chest_accounts')->insert([
            ['name' => 'Stripe', 'payment_method' => 'Stripe', 'current_balance' => 0, 'target_amount' => 0, 'currency' => 'mxn', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'HSBC',   'payment_method' => 'HSBC',   'current_balance' => 0, 'target_amount' => 0, 'currency' => 'mxn', 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'NU',     'payment_method' => 'NU',     'current_balance' => 0, 'target_amount' => 0, 'currency' => 'mxn', 'sort_order' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('war_chest_accounts');
    }
};
