<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot capturing which stores the customer wants us to visit on an
 * in-person PR. Used to compute the $10/store service fee at quote
 * time and to show admin a checklist of stores to hit.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_request_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['purchase_request_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_stores');
    }
};
