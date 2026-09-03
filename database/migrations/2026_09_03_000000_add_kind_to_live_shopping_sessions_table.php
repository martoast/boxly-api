<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remote store browser: a live session is either driven by the agent (`agent`,
 * today's flow) or by the customer (`manual`, the streamed store the customer
 * controls). Closed vocabulary; the default keeps every existing row an agent
 * session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_shopping_sessions', function (Blueprint $table) {
            $table->string('kind', 16)->default('agent')->after('store_id');
        });
    }

    public function down(): void
    {
        Schema::table('live_shopping_sessions', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
