<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The page a product was resolved FROM.
 *
 * Missed in the original table, and stage 4 cannot work without it: refreshing
 * a row means asking the panel to resolve that product again, and the panel
 * needs the URL. Reconstructing one from a title is guesswork; storing the one
 * that worked is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_index', function (Blueprint $table) {
            $table->text('source_url')->nullable()->after('store');
        });
    }

    public function down(): void
    {
        Schema::table('product_index', function (Blueprint $table) {
            $table->dropColumn('source_url');
        });
    }
};
