<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link an assisted purchase request to the chat it was placed from, so ONE
     * conversation = ONE request. The assistant re-shows the whole cart every
     * time the customer adds an item; without this link the client had no way
     * to tell "same shipment, more items" from "a brand new order" and created
     * a duplicate PR per item. Nullable: legacy, in-person and dashboard PRs
     * have no conversation.
     */
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->foreignId('conversation_id')
                ->nullable()
                ->after('user_id')
                ->constrained('conversations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversation_id');
        });
    }
};
