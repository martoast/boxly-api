<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            // Add image file fields
            $table->string('image_path', 1000)->nullable()->after('product_image_url');
            $table->string('image_filename')->nullable()->after('image_path');
            $table->string('image_mime_type')->nullable()->after('image_filename');
            $table->integer('image_size')->nullable()->after('image_mime_type');
            $table->string('image_url', 1000)->nullable()->after('image_size');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->dropColumn([
                'image_path',
                'image_filename',
                'image_mime_type',
                'image_size',
                'image_url',
            ]);
        });
    }
};