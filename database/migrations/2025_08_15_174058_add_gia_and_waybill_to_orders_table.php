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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('gia_path', 1000)->nullable()->after('notes');
            $table->string('gia_filename')->nullable()->after('gia_path');
            $table->string('gia_mime_type')->nullable()->after('gia_filename');
            $table->integer('gia_size')->nullable()->after('gia_mime_type');
            $table->string('gia_url', 1000)->nullable()->after('gia_size');
            
            // DHL Waybill number for tracking
            $table->string('dhl_waybill_number')->nullable()->after('gia_url');
            
            // Add indexes for performance
            $table->index('dhl_waybill_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['dhl_waybill_number']);
            
            // Drop columns
            $table->dropColumn([
                'gia_path',
                'gia_filename',
                'gia_mime_type',
                'gia_size',
                'gia_url',
                'dhl_waybill_number'
            ]);
        });
    }
};
