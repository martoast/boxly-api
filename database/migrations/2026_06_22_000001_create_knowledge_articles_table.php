<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed "wiki for the LLM": a set of focused markdown articles that power
 * the AI assistant's answers to business questions (everything that isn't a plain
 * product search). The whole published wiki is fed into the assistant's prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('section')->nullable();   // grouping, e.g. "Precios", "Envíos"
            $table->longText('content');             // markdown
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('is_published');
            $table->index('section');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_articles');
    }
};
