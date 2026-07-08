<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link each AI-search event to the chat it happened in, so admins can open
     * the full conversation thread from a search/question. Nullable: guest and
     * legacy events have no conversation.
     */
    public function up(): void
    {
        Schema::table('search_events', function (Blueprint $table) {
            $table->foreignId('conversation_id')
                ->nullable()
                ->after('user_id')
                ->constrained('conversations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('search_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversation_id');
        });
    }
};
