<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-person shopping at Las Americas, by the hour: the manager publishes open
 * hours (shopping_slots), a customer reserves N consecutive hours by paying the
 * first one, and the first CONFIRMED payment wins the hours: the rows of
 * shopping_reservation_slots are only written at confirm time, and
 * UNIQUE(active_slot_id) is what makes a double booking impossible.
 * Times are stored in UTC (starts_at) and shown/edited in Pacific (config in_person.timezone).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shopping_slots')) {
            Schema::create('shopping_slots', function (Blueprint $table) {
                $table->id();
                $table->string('location')->default('Las Americas Premium Outlets');
                $table->dateTime('starts_at'); // UTC, the start of the hour
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['location', 'starts_at']);
            });
        }

        if (! Schema::hasTable('shopping_reservations')) {
            Schema::create('shopping_reservations', function (Blueprint $table) {
                $table->id();
                $table->string('reservation_number')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->dateTime('starts_at'); // UTC, start of the first reserved hour
                $table->unsignedTinyInteger('hours_reserved');
                $table->decimal('hours_worked', 4, 2)->nullable();
                $table->decimal('amount_spent_usd', 10, 2)->nullable();
                // pending_payment|confirmed|slot_taken|cancelled|expired|completed
                $table->string('status')->default('pending_payment');
                $table->decimal('amount_usd', 8, 2);
                $table->string('stripe_checkout_session_id')->nullable()->index();
                $table->string('stripe_payment_intent_id')->nullable();
                $table->string('stripe_invoice_id')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->text('customer_notes')->nullable();
                $table->timestamp('confirmation_sent_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->foreignId('purchase_request_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();
                $table->index(['starts_at', 'status']);
            });
        }

        if (! Schema::hasTable('shopping_reservation_slots')) {
            Schema::create('shopping_reservation_slots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reservation_id')->constrained('shopping_reservations')->cascadeOnDelete();
                $table->foreignId('shopping_slot_id')->constrained('shopping_slots')->restrictOnDelete();
                // = shopping_slot_id while the reservation is confirmed, NULL once it is
                // cancelled/completed. The UNIQUE index decides who gets an hour.
                $table->unsignedBigInteger('active_slot_id')->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_reservation_slots');
        Schema::dropIfExists('shopping_reservations');
        Schema::dropIfExists('shopping_slots');
    }
};
