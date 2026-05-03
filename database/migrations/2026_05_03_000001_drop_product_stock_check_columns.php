<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the auto-stock-check columns from products and product_variants.
 *
 * Source-store availability is no longer auto-polled by a cron — Velonie
 * verifies each line manually when a customer's PR comes in (see the
 * stock_status column we added on purchase_request_items).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'last_stock_check_at')) {
                $table->dropColumn('last_stock_check_at');
            }
            if (Schema::hasColumn('products', 'stock_check_status')) {
                $table->dropColumn('stock_check_status');
            }
            if (Schema::hasColumn('products', 'last_stock_check_response')) {
                $table->dropColumn('last_stock_check_response');
            }
        });

        Schema::table('product_variants', function (Blueprint $table) {
            if (Schema::hasColumn('product_variants', 'stock_check_status')) {
                $table->dropColumn('stock_check_status');
            }
            if (Schema::hasColumn('product_variants', 'last_stock_check_at')) {
                $table->dropColumn('last_stock_check_at');
            }
            if (Schema::hasColumn('product_variants', 'last_stock_check_response')) {
                $table->dropColumn('last_stock_check_response');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('last_stock_check_at')->nullable();
            $table->string('stock_check_status', 32)->default('unknown');
            $table->text('last_stock_check_response')->nullable();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('stock_check_status', 32)->default('unknown');
            $table->timestamp('last_stock_check_at')->nullable();
            $table->text('last_stock_check_response')->nullable();
        });
    }
};
