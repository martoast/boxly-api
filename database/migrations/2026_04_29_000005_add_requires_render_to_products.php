<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * For products from JS-rendered SPAs (Gymshark, Victoria's Secret) where the
 * HTML returned by ScraperAPI's standard mode is a barebones shell. Setting
 * this flag tells the cron to pass &render=true (~10× credits, but works).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('requires_render')->default(false)->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('requires_render');
        });
    }
};
