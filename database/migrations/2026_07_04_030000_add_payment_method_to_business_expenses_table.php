<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Which account the expense was paid from (NU / HSBC / Stripe). When set,
     * the amount is debited from that War Chest account.
     */
    public function up(): void
    {
        Schema::table('business_expenses', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->after('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_expenses', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
