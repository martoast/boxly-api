<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A draft campaign may carry the moment it should start sending (boxly:campaigns-scheduled starts it then). Nullable, additive.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropColumn('scheduled_at');
        });
    }
};
