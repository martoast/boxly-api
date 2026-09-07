<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-chat rolling memory for the AI shopping assistant (phase 2 of the
 * bounded-context work). The chat backend keeps only the last few turns of a
 * thread verbatim in the prompt; everything older is folded into this running
 * summary by a cheap model after each reply, so a long chat keeps its context
 * without re-sending its whole history every turn.
 *
 * Additive and inert: nothing reads these columns until the chat backend's
 * CHAT_SUMMARY flag is on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // The summary text (es-MX, ≤ ~2,000 chars).
            $table->text('running_summary')->nullable()->after('title');
            // Id of the LAST conversation_messages row folded into the summary;
            // everything after it is still "unsummarized".
            $table->unsignedBigInteger('summary_upto_message_id')->nullable()->after('running_summary');
            // Optimistic concurrency for the writer: PATCH must present the
            // version it read, so two overlapping turns cannot clobber each other.
            $table->unsignedInteger('summary_version')->default(0)->after('summary_upto_message_id');
            $table->timestamp('summary_updated_at')->nullable()->after('summary_version');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['running_summary', 'summary_upto_message_id', 'summary_version', 'summary_updated_at']);
        });
    }
};
