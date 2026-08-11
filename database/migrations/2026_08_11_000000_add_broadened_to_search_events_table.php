<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A search whose specific query matched nothing falls back to the store's
 * general catalog. Until now that substitution was invisible: the event row
 * said "16 results" for a query nobody actually matched, so the admin
 * AI-search page showed a broadened miss as a hit. `broadened` records it, and
 * `served_query` keeps the query we really ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_events', function (Blueprint $table) {
            $table->boolean('broadened')->default(false)->after('results');
            $table->string('served_query')->nullable()->after('broadened');
        });
    }

    public function down(): void
    {
        Schema::table('search_events', function (Blueprint $table) {
            $table->dropColumn(['broadened', 'served_query']);
        });
    }
};
