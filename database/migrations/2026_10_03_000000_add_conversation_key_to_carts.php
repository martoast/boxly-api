<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONE OPEN CART PER CHAT (Alex 2026-10-03: "a new chat is a new order"). The cart's
 * chat is `conversation_key` — its conversation_id, or 0 for a cart with no chat
 * (the store-browse page, the extension) — because a NULL conversation_id would
 * never collide in a unique index. UNIQUE(user_id, active_slot) becomes
 * UNIQUE(user_id, conversation_key, active_slot): one open cart per user and chat.
 * Existing carts keep the chat that claimed them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_key')->default(0)->after('conversation_id');
        });

        DB::table('carts')->whereNotNull('conversation_id')->update(['conversation_key' => DB::raw('conversation_id')]);

        Schema::table('carts', function (Blueprint $table) {
            $table->unique(['user_id', 'conversation_key', 'active_slot']);
        });
        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'active_slot']);
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->unique(['user_id', 'active_slot']);
        });
        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'conversation_key', 'active_slot']);
            $table->dropColumn('conversation_key');
        });
    }
};
