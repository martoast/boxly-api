<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hero needs a separate mobile image — desktop hero photos are
 * cinematic landscape crops with the subject far right of frame, which
 * looks awful when sliced down to a portrait phone viewport. Letting
 * the shopping manager upload a dedicated portrait crop keeps the
 * subject framed correctly on every device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_heroes', function (Blueprint $table) {
            $table->string('mobile_image_path')->nullable()->after('image_url');
            $table->string('mobile_image_url')->nullable()->after('mobile_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('shop_heroes', function (Blueprint $table) {
            $table->dropColumn(['mobile_image_path', 'mobile_image_url']);
        });
    }
};
