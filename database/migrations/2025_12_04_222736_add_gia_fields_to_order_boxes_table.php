<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds GIA (shipping document) fields to order_boxes table.
     * Each physical box requires its own GIA file for shipping.
     */
    public function up(): void
    {
        Schema::table('order_boxes', function (Blueprint $table) {
            // Guia number (DHL tracking number) for this specific box
            $table->string('guia_number', 50)->nullable()->after('quantity');

            // GIA file storage fields
            $table->string('gia_path', 1000)->nullable()->after('guia_number');
            $table->string('gia_filename', 255)->nullable()->after('gia_path');
            $table->string('gia_mime_type', 50)->nullable()->after('gia_filename');
            $table->unsignedInteger('gia_size')->nullable()->after('gia_mime_type');
            $table->string('gia_url', 1000)->nullable()->after('gia_size');

            // Index for faster lookups by guia number
            $table->index('guia_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_boxes', function (Blueprint $table) {
            $table->dropIndex(['guia_number']);

            $table->dropColumn([
                'guia_number',
                'gia_path',
                'gia_filename',
                'gia_mime_type',
                'gia_size',
                'gia_url',
            ]);
        });
    }
};
