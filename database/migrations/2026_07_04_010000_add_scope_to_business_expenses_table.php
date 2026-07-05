<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * scope separates BUSINESS expenses (feed the dashboard profit) from
     * PERSONAL expenses (the owners' own rent/food/etc., tracked but never
     * counted against business profit). Existing rows default to 'business'.
     */
    public function up(): void
    {
        Schema::table('business_expenses', function (Blueprint $table) {
            $table->string('scope', 20)->default('business')->after('id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_expenses', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
