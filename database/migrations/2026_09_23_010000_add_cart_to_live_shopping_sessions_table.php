<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C3 — cart sync sessions. A `cart` session mirrors a Boxly cart's items for one
 * store into the customer's real store cart.
 *
 * `kind` is already a free string(16) column (2026_09_03), so `cart` needs no
 * schema change there — nothing MySQL-specific (no ENUM rewrite) and nothing
 * SQLite cannot run.
 *
 * ONE ACTIVE SYNC PER CART × STORE, enforced by the database the same way
 * active_slot is: `cart_active_key` is "<cart_id>:<store_id>" while the session
 * is pending/running and nulled by the terminal transition; the unique index
 * arbitrates concurrent jobs because NULLs never collide (MySQL and SQLite).
 * Cart sessions never take the user's active_slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_shopping_sessions', function (Blueprint $table) {
            $table->foreignId('cart_id')->nullable()->after('kind')
                ->constrained('carts')->nullOnDelete();
            $table->string('cart_active_key', 80)->nullable()->unique()->after('cart_id');
        });
    }

    public function down(): void
    {
        Schema::table('live_shopping_sessions', function (Blueprint $table) {
            $table->dropUnique(['cart_active_key']);
            $table->dropConstrainedForeignId('cart_id');
            $table->dropColumn('cart_active_key');
        });
    }
};
