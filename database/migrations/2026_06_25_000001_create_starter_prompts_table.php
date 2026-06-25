<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('starter_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('title');                 // short label shown on the card
            $table->text('prompt_text');             // what gets written into the chat input
            $table->string('image_url')->nullable(); // uploaded card image (takes priority)
            $table->string('image_query')->nullable(); // fallback: resolve a real product photo
            $table->string('emoji')->nullable();     // last-resort visual when no image
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('starter_prompts');
    }
};
