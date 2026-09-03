<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LiveShoppingDatabaseGuard;

/**
 * Base case for the live shopping suite.
 *
 * Why not RefreshDatabase? Several PRE-EXISTING migrations in this repo issue
 * raw MySQL (`ALTER TABLE ... MODIFY COLUMN ... ENUM(...)`), which SQLite cannot
 * execute, so the full chain cannot run under the default test connection. That
 * is a property of the repo, not of this feature — and it is why there are
 * effectively no feature tests here today.
 *
 * So we migrate exactly the tables this feature touches, using the REAL
 * migration files rather than hand-rolled Schema::create calls. That keeps the
 * migrations themselves under test (including the unique indexes the whole
 * concurrency design rests on) instead of testing a parallel schema that could
 * silently drift from what production runs.
 */
abstract class LiveShoppingTestCase extends TestCase
{
    /** Ordered by filename, the way the migrator runs them. */
    private const MIGRATIONS = [
        'database/migrations/2025_08_06_211407_create_users_table.php',
        'database/migrations/2025_08_06_211509_add_fields_to_users_table.php',
        'database/migrations/2025_08_06_211812_create_jobs_table.php',
        'database/migrations/2026_06_16_000001_create_conversations_tables.php',
        'database/migrations/2026_06_21_000001_create_search_events_table.php',
        'database/migrations/2026_06_21_000002_add_results_sample_to_search_events.php',
        'database/migrations/2026_06_22_000002_widen_search_events_query.php',
        'database/migrations/2026_07_07_000000_add_conversation_id_to_search_events_table.php',
        'database/migrations/2026_08_11_000000_add_broadened_to_search_events_table.php',
        'database/migrations/2026_09_01_000000_create_live_shopping_sessions_table.php',
        'database/migrations/2026_09_01_005000_create_live_shopping_webhook_receipts_table.php',
        'database/migrations/2026_09_01_010000_add_source_to_search_events_table.php',
        'database/migrations/2026_09_03_000000_add_kind_to_live_shopping_sessions_table.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // An application's ordinary DB_CONNECTION never authorizes destructive
        // test setup. MySQL requires a separate literal opt-in; every other run
        // is forced onto a fresh in-memory SQLite database.
        $mysqlGate = env('LIVE_SHOPPING_MYSQL_GATE') === '1';
        if ($mysqlGate) {
            config(['database.default' => 'mysql']);
        } else {
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        }

        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $database = (string) config("database.connections.{$connection}.database");
        $host = config("database.connections.{$connection}.host");

        // LOAD-BEARING: this must run before the first DROP or migration.
        LiveShoppingDatabaseGuard::assertSafe(
            (string) app()->environment(),
            $mysqlGate,
            $connection,
            $driver,
            $database,
            is_string($host) ? $host : null,
        );

        if (env('LIVE_SHOPPING_ANNOUNCE_CONNECTION')) {
            fwrite(STDERR, "\n[live-shopping] DB connection: {$connection} / {$database}\n");
        }

        // On a persistent server the previous test's tables are still there;
        // :memory: starts empty every time and does not need this.
        if ($mysqlGate) {
            $this->dropLiveTables();
        }

        foreach (self::MIGRATIONS as $path) {
            $this->artisan('migrate', ['--path' => $path, '--force' => true]);
        }

        $this->configureEngine();
    }

    /**
     * Drop this suite's tables between tests on a persistent connection.
     *
     * Child-first, with FK checks off: live_shopping_webhook_receipts points at
     * live_shopping_sessions, which points at conversations and users.
     */
    private function dropLiveTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        // EVERY table the migration list creates, or the next migrate() hits
        // "table already exists" on the first one that was missed. `migrations`
        // is dropped too so the whole chain re-runs.
        foreach ([
            'live_shopping_webhook_receipts', 'live_shopping_sessions',
            'search_events', 'conversation_messages', 'conversations',
            'jobs', 'job_batches', 'failed_jobs',
            'password_reset_tokens', 'sessions', 'users',
            'migrations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /** A fully configured, enabled feature. Individual tests unset what they test. */
    protected function configureEngine(array $overrides = []): void
    {
        config(['services.live_shopping_engine' => array_merge([
            'enabled'        => true,
            'base_url'       => 'https://engine.test',
            'service_secret' => 'service-secret',
            'service_key_id' => 'svc-k1',
            'webhook_keys'   => 'k1:webhook-secret',
            'timeout'        => 8,
            'skew'           => 300,
            'expiry_grace'   => 60,
            'drain_grace'    => 30,
            'orphan_horizon' => 300,
        ], $overrides)]);
    }

    protected function assertLiveTablesExist(): void
    {
        $this->assertTrue(Schema::hasTable('live_shopping_sessions'));
        $this->assertTrue(Schema::hasTable('live_shopping_webhook_receipts'));
    }
}
