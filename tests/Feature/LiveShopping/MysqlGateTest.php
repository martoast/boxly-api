<?php

namespace Tests\Feature\LiveShopping;

use App\Models\Conversation;
use App\Models\LiveShoppingSession;
use App\Models\LiveShoppingWebhookReceipt;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\LiveShoppingTestCase;

/**
 * The MySQL evidence gate — tasks/live-shopping-mysql-gate.md.
 *
 * SKIPPED on SQLite, deliberately and loudly. Two mechanisms carry the entire
 * correctness argument for this feature and NEITHER is exercised by the SQLite
 * suite:
 *
 *  1. UNIQUE(user_id, active_slot) — every "you already have a session running"
 *     answer, and the whole no-precheck race argument, rests on the database
 *     refusing the second insert.
 *  2. lockForUpdate() — SQLite ignores FOR UPDATE ENTIRELY. It is a silent
 *     no-op there, so the concurrency tests in the main suite pass for the
 *     wrong reason: the status check alone carries them.
 *
 * The rest of the suite proves the LOGIC. This file proves the ENGINE
 * underneath it behaves the way that logic assumes.
 */
class MysqlGateTest extends LiveShoppingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL evidence gate: run with DB_CONNECTION=mysql.');
        }
    }

    private function user(): User
    {
        return User::factory()->createQuietly();
    }

    private function activeSession(User $user): LiveShoppingSession
    {
        return LiveShoppingSession::create([
            'user_id'     => $user->id,
            'status'      => LiveShoppingSession::STATUS_RUNNING,
            'store_id'    => 'on',
            'stores'      => [],
            'objective'   => 'x',
            'active_slot' => 1,
        ]);
    }

    /**
     * THE SINGLE HIGHEST-VALUE ASSERTION IN THIS GATE.
     *
     * isActiveSlotCollision() matches on the driver's message text — the one
     * piece of this implementation that is driver-specific and, until this ran,
     * unverified on MySQL. If the classification misses, a real collision
     * surfaces to the customer as a 500 instead of a 409.
     */
    public function test_the_unique_index_raises_errno_1062_and_is_classified_as_a_collision(): void
    {
        $user = $this->user();
        $this->activeSession($user);

        try {
            $this->activeSession($user);
            $this->fail('MySQL accepted a second active slot for one user.');
        } catch (QueryException $e) {
            // MySQL semantics, observed rather than assumed.
            $this->assertSame('23000', $e->getCode());
            $this->assertStringContainsString('1062', $e->getMessage());
            $this->assertStringContainsString('active_slot', $e->getMessage());

            // And the controller's classifier actually recognises it.
            $classifier = new \ReflectionMethod(
                \App\Http\Controllers\LiveShoppingController::class,
                'isActiveSlotCollision',
            );
            $classifier->setAccessible(true);
            $this->assertTrue(
                $classifier->invoke(app(\App\Http\Controllers\LiveShoppingController::class), $e),
                'A real MySQL 1062 on active_slot was NOT classified as a collision — '
                . 'this would surface as a 500 instead of a 409.',
            );
        }
    }

    /** End to end, through the route: the second create is a 409, not a 500. */
    public function test_a_second_concurrent_create_is_a_409_through_the_route(): void
    {
        $user = $this->user();
        $conversation = Conversation::create(['user_id' => $user->id]);
        $this->activeSession($user);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(409);
    }

    /**
     * NULLs never collide in a unique index — the property the whole
     * active_slot design depends on. Many terminal rows coexist with one active.
     */
    public function test_many_terminal_rows_coexist_with_one_active_row(): void
    {
        $user = $this->user();

        for ($i = 0; $i < 5; $i++) {
            LiveShoppingSession::create([
                'user_id'     => $user->id,
                'status'      => LiveShoppingSession::STATUS_COMPLETED,
                'store_id'    => 'on',
                'stores'      => [],
                'objective'   => "old {$i}",
                'active_slot' => null,
            ]);
        }

        $this->activeSession($user);

        $this->assertSame(6, LiveShoppingSession::where('user_id', $user->id)->count());
        $this->assertSame(1, LiveShoppingSession::where('user_id', $user->id)
            ->whereNotNull('active_slot')->count());
    }

    /**
     * Row locks actually BLOCK — the claim SQLite cannot support.
     *
     * A second, genuinely separate connection is opened and given a 1-second
     * InnoDB lock wait. If lockForUpdate() is doing its job, that connection
     * cannot take the same row while the first transaction holds it, and MySQL
     * raises a lock wait timeout. On SQLite this test would pass trivially
     * because FOR UPDATE is ignored — which is exactly why it is MySQL-only.
     */
    public function test_lock_for_update_blocks_a_second_connection(): void
    {
        $user = $this->user();
        $session = $this->activeSession($user);

        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        DB::beginTransaction();

        try {
            // Hold the row.
            LiveShoppingSession::where('id', $session->id)->lockForUpdate()->first();

            $other->beginTransaction();
            $blocked = false;
            try {
                $other->query("SELECT id FROM live_shopping_sessions WHERE id = {$session->id} FOR UPDATE")
                    ->fetchAll();
            } catch (\PDOException $e) {
                // 1205 = Lock wait timeout exceeded. Proof the lock is real.
                $blocked = str_contains($e->getMessage(), '1205')
                    || stripos($e->getMessage(), 'lock wait timeout') !== false;
            }
            $other->rollBack();

            $this->assertTrue($blocked, 'FOR UPDATE did not block a second connection — the row lock is not real.');
        } finally {
            DB::rollBack();
        }
    }

    /**
     * And the lock is RELEASED on commit: the same second connection takes the
     * row immediately once the first transaction ends. A lock that never lets
     * go would deadlock the drainer instead of serialising it.
     */
    public function test_the_lock_is_released_after_the_transaction_ends(): void
    {
        $user = $this->user();
        $session = $this->activeSession($user);

        DB::beginTransaction();
        LiveShoppingSession::where('id', $session->id)->lockForUpdate()->first();
        DB::commit();

        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();
        $rows = $other->query("SELECT id FROM live_shopping_sessions WHERE id = {$session->id} FOR UPDATE")
            ->fetchAll();
        $other->rollBack();

        $this->assertCount(1, $rows);
    }

    /**
     * Column types survive the round trip on real MySQL — the schema the
     * migrations actually produce, not the one SQLite approximates. SQLite is
     * dynamically typed, so it cannot fail these.
     */
    public function test_column_types_are_what_the_design_assumes(): void
    {
        $columns = DB::table('information_schema.columns')
            ->select('TABLE_NAME', 'COLUMN_NAME', 'DATA_TYPE', 'COLUMN_TYPE', 'IS_NULLABLE')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
            ->whereIn('TABLE_NAME', ['live_shopping_sessions', 'live_shopping_webhook_receipts'])
            ->get()
            ->keyBy(fn ($c) => $c->TABLE_NAME . '.' . $c->COLUMN_NAME);

        $this->assertSame('timestamp', $columns['live_shopping_sessions.expires_at']->DATA_TYPE);

        foreach (['latest_seq', 'terminal_seq'] as $col) {
            $type = $columns["live_shopping_sessions.{$col}"]->COLUMN_TYPE;
            $this->assertStringContainsString('bigint', $type);
            $this->assertStringContainsString('unsigned', $type, "{$col} must be unsigned");
        }

        $this->assertSame('json', $columns['live_shopping_webhook_receipts.payload']->DATA_TYPE);

        // content_sha256 must hold exactly 64 hex characters.
        $sha = $columns['live_shopping_webhook_receipts.content_sha256']->COLUMN_TYPE;
        $this->assertMatchesRegularExpression('/(char|varchar)\(\d+\)/', $sha);
        preg_match('/\((\d+)\)/', $sha, $m);
        $this->assertGreaterThanOrEqual(64, (int) $m[1]);
    }

    /** The unique indexes the concurrency design rests on actually exist. */
    public function test_the_load_bearing_indexes_exist(): void
    {
        $indexes = DB::select('SHOW INDEX FROM live_shopping_sessions');
        $unique = [];
        foreach ($indexes as $i) {
            if ((int) $i->Non_unique === 0) {
                $unique[$i->Key_name][] = $i->Column_name;
            }
        }

        $this->assertContains(['user_id', 'active_slot'], array_values($unique));
        $this->assertContains(['engine_session_id'], array_values($unique));

        $receiptIndexes = DB::select('SHOW INDEX FROM live_shopping_webhook_receipts');
        $receiptUnique = [];
        foreach ($receiptIndexes as $i) {
            if ((int) $i->Non_unique === 0) {
                $receiptUnique[$i->Key_name][] = $i->Column_name;
            }
        }
        $this->assertContains(['delivery_id'], array_values($receiptUnique));
    }

    /** JSON and timestamp values round-trip through real MySQL columns. */
    public function test_json_and_timestamp_values_round_trip(): void
    {
        $user = $this->user();
        $session = LiveShoppingSession::create([
            'user_id'     => $user->id,
            'status'      => LiveShoppingSession::STATUS_RUNNING,
            'store_id'    => 'on',
            'stores'      => [['id' => 'on'], ['id' => 'nike']],
            'objective'   => 'x',
            'expires_at'  => '2026-09-01T12:34:56Z',
            'latest_seq'  => 4294967296,   // beyond 32 bits, on purpose
            'active_slot' => 1,
        ]);

        $fresh = $session->fresh();
        $this->assertSame([['id' => 'on'], ['id' => 'nike']], $fresh->stores);
        $this->assertSame(4294967296, $fresh->latest_seq);
        $this->assertSame('2026-09-01 12:34:56', $fresh->expires_at->utc()->format('Y-m-d H:i:s'));

        $receipt = LiveShoppingWebhookReceipt::create([
            'delivery_id'    => 'dlv_roundtrip',
            'content_sha256' => str_repeat('a', 64),
            'payload'        => ['nested' => ['a' => 1], 'list' => [1, 2, 3]],
            'status'         => LiveShoppingWebhookReceipt::STATUS_RECEIVED,
            'terminal_seq'   => 7,
            'outcome'        => 'completed',
            'received_at'    => now(),
        ]);

        // assertEquals, NOT assertSame — and the difference is a real MySQL
        // property worth stating: a native JSON column stores objects in a
        // sorted binary format, so KEY ORDER IS NOT PRESERVED across the round
        // trip (SQLite, which stores the raw text, does preserve it).
        //
        // That is fine here only because nothing reads this payload
        // positionally: the job accesses it by key, and the
        // assistant_part/result.products agreement check runs on the REQUEST
        // bytes before persistence, never on the stored copy. Anything that
        // ever byte-compares a stored payload would be broken by this.
        $stored = $receipt->fresh()->payload;
        $this->assertEquals(['nested' => ['a' => 1], 'list' => [1, 2, 3]], $stored);
        // Values and nesting survive exactly; only ordering moves.
        $this->assertSame([1, 2, 3], $stored['list']);
        $this->assertSame(1, $stored['nested']['a']);

        $this->assertSame(64, strlen($receipt->fresh()->content_sha256));
    }

    /** A raw second PDO connection — genuinely separate, not a pooled clone. */
    private function secondConnection(): PDO
    {
        $c = config('database.connections.mysql');

        return new PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['database']}",
            $c['username'],
            $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
