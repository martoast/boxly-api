<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Shopping — one conversation-attached remote shopping session (P1).
 *
 * ONE ACTIVE SESSION PER USER, enforced by the database, not by a precheck.
 * `active_slot` is set to 1 on create and nulled in the same transaction as any
 * terminal transition; UNIQUE(user_id, active_slot) then does the work, because
 * NULLs never collide in a unique index on either MySQL or SQLite.
 *
 * Why not a filtered/partial unique index ("UNIQUE(user_id) WHERE status IN
 * (...)")? MySQL has no such thing — that is Postgres. The MySQL-viable
 * alternative is a stored generated column, but its expression is MySQL-specific
 * while the test suite runs on SQLite, and a constraint that exists in prod but
 * not under test is worse than no constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_shopping_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Required at validation, nullable here: the FK is nullOnDelete, so
            // deleting a conversation nulls this WHILE a session is running.
            // The result job treats that as "deleted thread" (skip the append,
            // still complete the terminal transition), not a correlation failure.
            $table->foreignId('conversation_id')->nullable()
                ->constrained('conversations')->nullOnDelete();

            // Nullable because the row exists before the engine answers; unique
            // so a replayed delivery can never fan out to two rows.
            $table->string('engine_session_id')->nullable()->unique();

            $table->string('status', 20)->index();   // pending|running|completed|failed|cancelled
            $table->string('store_id', 40)->nullable();
            $table->json('stores');                  // P2-shaped; one entry in P1
            $table->text('objective');

            // The ENGINE's hard deadline, echoed by the accepted create response.
            // Never derived from created_at: a blind local timeout would kill
            // sessions the engine still considers alive.
            $table->timestamp('expires_at')->nullable();

            $table->unsignedBigInteger('latest_seq')->nullable();
            $table->unsignedBigInteger('terminal_seq')->nullable();
            $table->string('terminal_delivery_id')->nullable();
            $table->string('error_code', 120)->nullable();

            $table->unsignedTinyInteger('active_slot')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'active_slot']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);   // the reconciler's query
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_shopping_sessions');
    }
};
