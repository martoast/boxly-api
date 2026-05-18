<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-configured availability for in-person shopping trips at Las Americas
 * Premium Outlets (and any future malls — `location` is generic). Customers
 * see open trips in the in-person flow's date picker; multiple customers can
 * book the same trip and Boxly handles them all in one visit.
 *
 * No capacity column — every trip is unlimited; the team consolidates whatever
 * gets booked onto an open day.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopping_trips', function (Blueprint $table) {
            $table->id();
            $table->string('location')->default('Las Americas Premium Outlets');
            $table->date('trip_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->enum('status', ['open', 'closed', 'completed'])->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'trip_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_trips');
    }
};
