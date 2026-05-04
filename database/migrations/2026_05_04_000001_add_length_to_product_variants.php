<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third variant axis — `length` (a.k.a. inseam).
 *
 * Lululemon Wunder Train tights and similar products have color × size ×
 * length (e.g. 25" / 28"). The product page renders length as its own
 * picker, so it has to round-trip through the system end-to-end:
 * extension extraction → DB → admin edit form → public product page →
 * cart → PR item.
 *
 * Most products won't have it (length stays null), so the picker hides
 * itself when no variant on a product has a length value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('length')->nullable()->after('color');
            // The old (product_id, size, color) unique key would forbid
            // a 28" Black M and a 25" Black M from coexisting. Replace
            // it with one that includes length.
            $table->dropUnique(['product_id', 'size', 'color']);
            $table->unique(['product_id', 'size', 'color', 'length']);
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'size', 'color', 'length']);
            $table->unique(['product_id', 'size', 'color']);
            $table->dropColumn('length');
        });
    }
};
