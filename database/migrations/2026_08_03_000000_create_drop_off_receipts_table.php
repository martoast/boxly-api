<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof that Boxly physically received something a customer handed over.
 *
 * Deliberately standalone — not an Order, not a Purchase Request. A drop-off is
 * just "this person gave us this stuff on this day", written down so the
 * customer has a confirmation in their inbox. Wiring it to an order would force
 * an order to exist first, which is exactly the moment we don't have one.
 *
 * Photos live in a JSON column rather than their own table (cf.
 * order_arrival_images): a receipt has three real fields, and nothing ever
 * queries an individual photo — they are only ever read back as the whole set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drop_off_receipts', function (Blueprint $table) {
            $table->id();

            // DO + 6 random upper — same shape as Order::generateOrderNumber().
            $table->string('receipt_number', 16)->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('description');
            $table->date('dropped_off_at');

            // [{ path, url, filename, mime_type, size }, ...]
            $table->json('images')->nullable();

            // Null = drafted but never emailed. Set on each send, so the UI can
            // offer "resend" without losing that it went out at least once.
            $table->timestamp('email_sent_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'dropped_off_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drop_off_receipts');
    }
};
