<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Checkbook ledger per War Chest account: every movement in/out (order
     * deposits, expense payments, manual entries, balance adjustments).
     */
    public function up(): void
    {
        Schema::create('war_chest_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('war_chest_accounts')->cascadeOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 12, 2);                 // always positive
            $table->decimal('balance_after', 14, 2)->nullable(); // account balance right after this movement
            $table->string('source_type', 20)->default('manual'); // order | expense | manual | adjustment | opening
            $table->unsignedBigInteger('source_id')->nullable();   // order/expense id when applicable
            $table->string('description')->nullable();
            $table->dateTime('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['account_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('war_chest_transactions');
    }
};
