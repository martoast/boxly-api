<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A store-cart add whose agent run failed (the model API erroring mid-add, a lost browser) is tried once more before
// the line is marked failed — the shopper was told "se reintentará" and nothing retried (live Lab 2026-09-28).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('sync_failures')->default(0)->after('sync_note');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('sync_failures');
        });
    }
};
