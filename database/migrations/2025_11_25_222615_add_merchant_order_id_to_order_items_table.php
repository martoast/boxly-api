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
            // Adding the store's order number (e.g., Amazon Order #)
            // Placing it after 'retailer' for logical grouping
            $table->string('merchant_order_id')->nullable()->after('retailer');
            
            // Optional: Add index if you plan to search by this ID frequently
            $table->index('merchant_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('merchant_order_id');
        });
    }
};