<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Product/image URLs can be very long (Amazon & co. pack tracking + affiliate
     * params). They were VARCHAR(1000) while validation allowed 2000, so a long
     * link passed validation then failed the INSERT as an uncaught 500. Widen
     * them to TEXT so customers can paste any link.
     *
     * Note: on MySQL, widening VARCHAR->TEXT can emit warning 1265 ("data
     * truncated") for a legacy row whose bytes aren't clean in the charset — and
     * STRICT mode turns that warning into an error that aborts the migration.
     * VARCHAR(1000)->TEXT never loses length (TEXT holds 65,535 bytes), so we
     * relax STRICT mode just for these ALTERs.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $prevMode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
            DB::statement("SET SESSION sql_mode = REPLACE(REPLACE(@@SESSION.sql_mode, 'STRICT_TRANS_TABLES', ''), 'STRICT_ALL_TABLES', '')");

            try {
                DB::statement('ALTER TABLE purchase_request_items MODIFY product_url TEXT NOT NULL');
                DB::statement('ALTER TABLE purchase_request_items MODIFY product_image_url TEXT NULL');
                DB::statement('ALTER TABLE purchase_request_items MODIFY image_path TEXT NULL');
                DB::statement('ALTER TABLE purchase_request_items MODIFY image_url TEXT NULL');
            } finally {
                DB::statement('SET SESSION sql_mode = ' . DB::getPdo()->quote($prevMode));
            }

            return;
        }

        // Other drivers (e.g. sqlite in local/tests) — native schema change.
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
