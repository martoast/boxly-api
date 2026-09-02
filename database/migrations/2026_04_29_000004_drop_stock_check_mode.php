<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop stock_check_mode entirely. Product verification is handled by the
 * authenticated live-shopping pipeline, so products no longer carry a
 * provider-specific code-path selector.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock_check_mode');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('stock_check_mode', ['auto', 'manual'])
                ->default('auto')
                ->after('source_url');
        });
    }
};
