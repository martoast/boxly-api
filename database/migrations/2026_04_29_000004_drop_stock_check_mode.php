<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop stock_check_mode entirely. ScraperAPI works for every source URL —
 * Shopify, Cloudflare-protected, plain HTML — so there's no scenario where
 * a product needs a different code path. The cron just runs against every
 * active product with a source_url.
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
