<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card photos used to be resolved at request time (StarterPromptController's
 * live product search), which is why the search page's starter cards took
 * seconds to paint. resolved_image_url is filled in ONCE, at admin save
 * time, so the client just reads a stable URL. Kept separate from
 * image_url so a hand-uploaded photo is never clobbered by auto-resolution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('starter_prompts', function (Blueprint $table) {
            $table->string('resolved_image_url')->nullable()->after('image_query');
        });
    }

    public function down(): void
    {
        Schema::table('starter_prompts', function (Blueprint $table) {
            $table->dropColumn('resolved_image_url');
        });
    }
};
