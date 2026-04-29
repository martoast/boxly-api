<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // 'none'       — direct curl works (free, default)
            // 'cloudflare' — needs to go through ScraperAPI (paid, used for protected stores)
            // 'manual'     — admin maintains stock manually (no automated check)
            $table->enum('source_protection', ['none', 'cloudflare', 'manual'])
                ->default('none')
                ->after('source_url');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('source_protection');
        });
    }
};
