<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Move color from being a variant axis to being a product-level field.
 *
 * One Boxly product now represents ONE specific color of a source-store
 * product. Different colors of the same source SKU group become
 * different Boxly products (with potentially different prices, image
 * sets, and per-color stock realities). Variants narrow down to size ×
 * length only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('color')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
