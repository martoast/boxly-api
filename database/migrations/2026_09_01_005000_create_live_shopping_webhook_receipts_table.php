<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Shopping — the DURABLE INBOX for terminal webhook deliveries.
 *
 * This is not an audit log with a unique index bolted on: it IS the queue. The
 * webhook's only job is to commit a row here, and the 202 is not returned until
 * it has. Everything downstream reads from this table.
 *
 * `insertOrIgnore` on the unique delivery_id is the arbiter — whoever loses the
 * race reads the winner's row in the same transaction. A read-then-decide design
 * would be racy: two concurrent same-id/different-body requests could both find
 * nothing and both answer 202, making the documented 409 unreachable.
 *
 * content_sha256 is what makes "same delivery_id, different body" a detectable
 * CONFLICT rather than a silent duplicate. Without it, two different bodies
 * sharing one id look exactly like a replay and one is dropped without trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_shopping_webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_id')->unique();
            $table->string('content_sha256', 64);
            $table->json('payload');                 // bounded + validated at accept time
            $table->string('status', 20)->index();   // received|processed|conflict|failed
            $table->foreignId('live_shopping_session_id')->nullable()
                ->constrained('live_shopping_sessions')->nullOnDelete();
            $table->string('outcome', 20)->nullable();
            $table->unsignedBigInteger('terminal_seq')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'received_at']);   // the drainer's query
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_shopping_webhook_receipts');
    }
};
