<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_manual_metrics', function (Blueprint $table) {
            // Financial metrics
            $table->decimal('total_revenue', 10, 2)->default(0)->after('month');
            $table->decimal('total_expenses', 10, 2)->default(0)->after('total_revenue');
            $table->decimal('total_profit', 10, 2)->default(0)->after('total_expenses');
            
            // Order metrics
            $table->integer('total_orders')->default(0)->after('total_profit');
            
            // Box distribution
            $table->integer('boxes_extra_small')->default(0)->after('total_orders');
            $table->integer('boxes_small')->default(0)->after('boxes_extra_small');
            $table->integer('boxes_medium')->default(0)->after('boxes_small');
            $table->integer('boxes_large')->default(0)->after('boxes_medium');
            $table->integer('boxes_extra_large')->default(0)->after('boxes_large');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_manual_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'total_revenue',
                'total_expenses',
                'total_profit',
                'total_orders',
                'boxes_extra_small',
                'boxes_small',
                'boxes_medium',
                'boxes_large',
                'boxes_extra_large',
            ]);
        });
    }
};