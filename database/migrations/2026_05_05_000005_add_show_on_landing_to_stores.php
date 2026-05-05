<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the shopping manager curate which brands appear in the
 * landing-page brand showcase. Without this flag the showcase shows
 * every active store, which clutters the page once we have many
 * stores. Default false so newly added stores don't auto-promote
 * themselves to the landing — but we backfill the existing top 5
 * stores so the landing isn't suddenly empty after deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('show_on_landing')->default(false)->after('is_active');
            $table->index('show_on_landing');
        });

        $ids = DB::table('stores')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(5)
            ->pluck('id')
            ->all();

        if (! empty($ids)) {
            DB::table('stores')->whereIn('id', $ids)->update(['show_on_landing' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['show_on_landing']);
            $table->dropColumn('show_on_landing');
        });
    }
};
