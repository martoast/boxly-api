<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_order_items', function (Blueprint $table) {
            // Nullable — legacy items created before variants existed have no variant.
            $table->foreignId('variant_id')->nullable()->after('product_id')
                ->constrained('product_variants')->nullOnDelete();

            // Snapshots so historical orders are stable even if the variant is deleted
            $table->string('size_snapshot')->nullable()->after('image_url_snapshot');
            $table->string('color_snapshot')->nullable()->after('size_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_order_items', function (Blueprint $table) {
            $table->dropForeign(['variant_id']);
            $table->dropColumn(['variant_id', 'size_snapshot', 'color_snapshot']);
        });
    }
};
