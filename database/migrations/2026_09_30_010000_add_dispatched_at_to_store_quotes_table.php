<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sequential checkout: when a store's quote job was claimed for dispatch (one winner per quote). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_quotes') && ! Schema::hasColumn('store_quotes', 'dispatched_at')) {
            Schema::table('store_quotes', fn (Blueprint $table) => $table->timestamp('dispatched_at')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('store_quotes', 'dispatched_at')) {
            Schema::table('store_quotes', fn (Blueprint $table) => $table->dropColumn('dispatched_at'));
        }
    }
};
