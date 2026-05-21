<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag a product for the landing page's "Trending en USA" rail.
 * Indexed for the small public query that powers the rail (returns up
 * to 8 active featured products). Stays false by default so existing
 * catalog behavior is unchanged.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('status');
            $table->index('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['is_featured']);
            $table->dropColumn('is_featured');
        });
    }
};
