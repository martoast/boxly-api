<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usage analytics for the AI search feature — every search and product view is
 * logged here so the admin dashboard can gauge adoption (queries, stores, views,
 * conversion to purchase requests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->index();      // 'search' | 'product_view'
            $table->string('query', 255)->nullable();
            $table->string('store', 120)->nullable()->index();
            $table->string('title', 255)->nullable();
            $table->string('url', 2000)->nullable();
            $table->unsignedInteger('results')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_events');
    }
};
