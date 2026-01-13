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
            $table->string('arrival_image_path')->nullable()->after('notes');
            $table->string('arrival_image_filename')->nullable()->after('arrival_image_path');
            $table->string('arrival_image_mime_type')->nullable()->after('arrival_image_filename');
            $table->unsignedInteger('arrival_image_size')->nullable()->after('arrival_image_mime_type');
            $table->string('arrival_image_url')->nullable()->after('arrival_image_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'arrival_image_path',
                'arrival_image_filename',
                'arrival_image_mime_type',
                'arrival_image_size',
                'arrival_image_url',
            ]);
        });
    }
};
