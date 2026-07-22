<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outbox of order status transitions. Every change lands here as a row
     * (written by a queued job fired from the Order model's status watcher),
     * so external consumers — Jarvis's CRM sync on Alex's machine — can pull
     * exact, ordered events past a cursor instead of diffing order lists.
     * Durable: events wait out consumer downtime.
     */
    public function up(): void
    {
        Schema::create('order_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable(); // null = order created
            $table->string('to_status');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_events');
    }
};
