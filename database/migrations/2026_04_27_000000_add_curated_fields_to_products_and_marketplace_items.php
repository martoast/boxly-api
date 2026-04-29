<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Curated drop-ship fields — admin-only, never exposed to customers
            $table->unsignedInteger('cost_cents')->nullable()->after('price_cents');
            $table->decimal('markup_percent', 5, 2)->default(8.00)->after('cost_cents');

            // Stock check via cron
            $table->timestamp('last_stock_check_at')->nullable()->after('stock');
            $table->enum('stock_check_status', ['unknown', 'in_stock', 'out_of_stock'])
                ->default('unknown')
                ->after('last_stock_check_at');
            $table->text('last_stock_check_response')->nullable()->after('stock_check_status');
        });

        Schema::table('marketplace_order_items', function (Blueprint $table) {
            // Tracking of admin's purchase from source store (after customer pays Boxly)
            $table->timestamp('source_purchased_at')->nullable()->after('received_at');
            $table->string('source_order_id')->nullable()->after('source_purchased_at');
            $table->string('source_tracking_number')->nullable()->after('source_order_id');
            $table->string('source_carrier')->nullable()->after('source_tracking_number');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'cost_cents',
                'markup_percent',
                'last_stock_check_at',
                'stock_check_status',
                'last_stock_check_response',
            ]);
        });

        Schema::table('marketplace_order_items', function (Blueprint $table) {
            $table->dropColumn([
                'source_purchased_at',
                'source_order_id',
                'source_tracking_number',
                'source_carrier',
            ]);
        });
    }
};
