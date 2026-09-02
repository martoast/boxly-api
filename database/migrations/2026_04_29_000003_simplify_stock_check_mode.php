<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace `source_protection (none|cloudflare|manual)` with the simpler
 * `stock_check_mode (auto|manual)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('stock_check_mode', ['auto', 'manual'])
                ->default('auto')
                ->after('source_url');
        });

        // Migrate any existing rows: 'manual' stays manual, anything else → auto
        DB::statement("UPDATE products SET stock_check_mode = CASE WHEN source_protection = 'manual' THEN 'manual' ELSE 'auto' END");

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('source_protection');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('source_protection', ['none', 'cloudflare', 'manual'])
                ->default('none')
                ->after('source_url');
        });
        DB::statement("UPDATE products SET source_protection = CASE WHEN stock_check_mode = 'manual' THEN 'manual' ELSE 'none' END");
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock_check_mode');
        });
    }
};
