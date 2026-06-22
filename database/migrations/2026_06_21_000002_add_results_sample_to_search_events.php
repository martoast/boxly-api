<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the results our algorithm returned for each search (top items + their
 * stores/prices), so the admin can analyze the query→results relationship and
 * tune search quality — the way a search engine evaluates what it served.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_events', function (Blueprint $table) {
            $table->json('results_sample')->nullable()->after('results');
        });
    }

    public function down(): void
    {
        Schema::table('search_events', function (Blueprint $table) {
            $table->dropColumn('results_sample');
        });
    }
};
