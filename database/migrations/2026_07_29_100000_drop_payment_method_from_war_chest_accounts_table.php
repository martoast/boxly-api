<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The War Chest is now pure CRUD — nothing in the app moves a balance
     * automatically. `payment_method` existed only as the routing key that
     * matched an order's paid_location / an expense's payment_method to an
     * account, and its UNIQUE index blocked having two Stripe accounts.
     * Both the routing and the column go away.
     */
    public function up(): void
    {
        Schema::table('war_chest_accounts', function (Blueprint $table) {
            $table->dropUnique('war_chest_accounts_payment_method_unique');
            $table->dropColumn('payment_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('war_chest_accounts', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->unique()->after('name');
        });
    }
};
