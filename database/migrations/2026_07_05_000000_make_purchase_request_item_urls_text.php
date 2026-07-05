<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Product/image URLs can be very long (Amazon & co. pack tracking + affiliate
     * params). They were VARCHAR(1000) while validation allowed 2000, so a long
     * link passed validation then failed the INSERT (SQLSTATE 1406) as an
     * uncaught 500. Widen them to TEXT so customers can paste any link.
     */
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->text('product_url')->change();
            $table->text('product_image_url')->nullable()->change();
            $table->text('image_path')->nullable()->change();
            $table->text('image_url')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->string('product_url', 1000)->change();
            $table->string('product_image_url', 1000)->nullable()->change();
            $table->string('image_path', 1000)->nullable()->change();
            $table->string('image_url', 1000)->nullable()->change();
        });
    }
};
