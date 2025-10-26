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
        Schema::table('order_items', function (Blueprint $table) {
            $table->date('estimated_delivery_date')->nullable()->after('tracking_url');
            
            // Add index for efficient filtering
            $table->index('estimated_delivery_date');
            $table->index(['estimated_delivery_date', 'arrived']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_items_estimated_delivery_date_index']);
            $table->dropIndex(['order_items_estimated_delivery_date_arrived_index']);
            $table->dropColumn('estimated_delivery_date');
        });
    }
};