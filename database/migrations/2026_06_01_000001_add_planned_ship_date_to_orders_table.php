<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the planned ship date used by the Weekly Operations Board.
     * Set when an admin consolidates an order (and editable manually).
     * Additive + nullable — does not affect existing orders or payment logic.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->date('planned_ship_date')->nullable()->after('estimated_delivery_date');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('planned_ship_date');
        });
    }
};
