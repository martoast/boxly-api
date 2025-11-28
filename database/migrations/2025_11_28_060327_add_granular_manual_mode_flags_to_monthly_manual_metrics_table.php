<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_manual_metrics', function (Blueprint $table) {
            // Granular manual mode flags
            $table->boolean('is_financial_manual')->default(false)->after('is_manual_mode');
            $table->boolean('is_boxes_manual')->default(false)->after('is_financial_manual');
            $table->boolean('is_orders_manual')->default(false)->after('is_boxes_manual');
        });

        // Migrate existing data: if is_manual_mode was true, set all granular flags to true
        DB::table('monthly_manual_metrics')
            ->where('is_manual_mode', true)
            ->update([
                'is_financial_manual' => true,
                'is_boxes_manual' => true,
                'is_orders_manual' => true,
            ]);
    }

    public function down(): void
    {
        Schema::table('monthly_manual_metrics', function (Blueprint $table) {
            $table->dropColumn([
                'is_financial_manual',
                'is_boxes_manual',
                'is_orders_manual',
            ]);
        });
    }
};
