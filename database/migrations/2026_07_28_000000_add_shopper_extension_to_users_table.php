<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track Boxly Shopper (Chrome extension) adoption per user.
 *
 * Columns live on `users` rather than in a separate events table because the
 * question is "how many of our customers have it installed" — a per-user state,
 * not a stream. Counting is then a single WHERE, and a user who reinstalls or
 * uses a second computer doesn't inflate the number.
 *
 * NOT to be confused with the admin Product Capturer extension, which uses
 * `/me/extension-token`. This is the customer-facing shopper panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // First time we ever saw the extension on this account. Never reset,
            // so "installed" stays true even if they later stop using it —
            // churn is read from last_seen_at going stale.
            $table->timestamp('shopper_extension_installed_at')->nullable()->after('shopping_profile');
            // Most recent sighting. This is the ACTIVE-usage signal.
            $table->timestamp('shopper_extension_last_seen_at')->nullable()->after('shopper_extension_installed_at');
            // Manifest version, so we can see who is still on an old build.
            $table->string('shopper_extension_version', 20)->nullable()->after('shopper_extension_last_seen_at');

            $table->index('shopper_extension_installed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['shopper_extension_installed_at']);
            $table->dropColumn([
                'shopper_extension_installed_at',
                'shopper_extension_last_seen_at',
                'shopper_extension_version',
            ]);
        });
    }
};
