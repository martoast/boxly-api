<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_boxes', function (Blueprint $table) {
            $table->decimal('length', 8, 2)->nullable()->after('quantity');
            $table->decimal('width', 8, 2)->nullable()->after('length');
            $table->decimal('height', 8, 2)->nullable()->after('width');
            $table->decimal('weight', 8, 2)->nullable()->after('height');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_boxes', function (Blueprint $table) {
            $table->dropColumn(['length', 'width', 'height', 'weight']);
        });
    }
};
