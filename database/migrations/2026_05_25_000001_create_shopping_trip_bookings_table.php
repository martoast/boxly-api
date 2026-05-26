<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_trip_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('shopping_trip_id')->constrained()->onDelete('cascade');
            $table->json('store_ids');                  // [1, 3, 8]
            $table->json('store_categories')->nullable(); // {"1":[1,2],"3":[2]}
            $table->string('booking_number')->unique();
            $table->decimal('deposit_amount_usd', 8, 2);
            $table->string('stripe_checkout_session_id')->nullable()->index();
            $table->string('status')->default('pending_payment'); // pending_payment|confirmed|cancelled
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_trip_bookings');
    }
};
