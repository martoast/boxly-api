<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questions to the assistant can be longer than a product search, so widen the
 * `query` column from varchar(255) to TEXT. Searches and product views are
 * unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_events', function (Blueprint $table) {
            $table->text('query')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('search_events', function (Blueprint $table) {
            $table->string('query', 255)->nullable()->change();
        });
    }
};
