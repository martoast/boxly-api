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
            // Adding fields for direct product image file uploads
            // We typically place these after product_image_url
            $table->string('product_image_path', 1000)->nullable()->after('product_image_url');
            $table->string('product_image_filename')->nullable()->after('product_image_path');
            $table->string('product_image_mime_type')->nullable()->after('product_image_filename');
            $table->integer('product_image_size')->nullable()->after('product_image_mime_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'product_image_path',
                'product_image_filename',
                'product_image_mime_type',
                'product_image_size',
            ]);
        });
    }
};