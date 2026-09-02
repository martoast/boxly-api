<?php

namespace Tests\Feature\LiveShopping;

use App\Models\Conversation;
use App\Models\LiveShoppingSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\LiveShoppingTestCase;

class CreateSessionTest extends LiveShoppingTestCase
{
    private function engineOk(array $overrides = []): void
    {
        Http::fake(['engine.test/*' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1,
            'session' => array_merge([
                'id'              => 'eng_1',
                'conversation_id' => '1',
                'store_id'        => 'on',
                'status'          => 'running',
                'latest_seq'      => 5,
                'created_at'      => now()->toIso8601String(),
                'expires_at'      => now()->addMinutes(10)->toIso8601String(),
            ], $overrides),
        ]], 201)]);
    }

    private function actor(): array
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 't']);

        return [$user, $conversation];
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/live-shopping/sessions', [])->assertStatus(401);
    }

    public function test_creates_a_session_and_persists_the_engine_answer(): void
    {
        [$user, $conversation] = $this->actor();
        $this->engineOk(['conversation_id' => (string) $conversation->id]);

        $response = $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id,
            'objective'       => 'check stock of the cloudmonster',
            'store_id'        => 'on',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.engine_session_id', 'eng_1')
            // running, not pending: the engine confirmed durable acceptance.
            ->assertJsonPath('data.status', 'running');

        $session = LiveShoppingSession::first();
        $this->assertSame(5, $session->latest_seq);
        $this->assertNotNull($session->expires_at);
        $this->assertSame(1, (int) $session->active_slot);
    }

    public function test_conversation_id_is_required(): void
    {
        [$user] = $this->actor();

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(422);
    }

    public function test_another_users_conversation_is_rejected(): void
    {
        [$user] = $this->actor();
        $other = User::factory()->createQuietly();
        $theirs = Conversation::create(['user_id' => $other->id]);
        $this->engineOk();

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $theirs->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(403);

        $this->assertSame(0, LiveShoppingSession::count());
    }

    public function test_objective_and_store_id_are_validated(): void
    {
        [$user, $conversation] = $this->actor();

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => '', 'store_id' => 'on',
        ])->assertStatus(422);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'NOT A SLUG',
        ])->assertStatus(422);
    }

    /** Proves no retailer allowlist was introduced: the engine owns the catalog. */
    public function test_a_store_id_unknown_to_this_repo_is_accepted(): void
    {
        [$user, $conversation] = $this->actor();
        $this->engineOk([
            'conversation_id' => (string) $conversation->id,
            'store_id'        => 'some-store-nobody-here-has-heard-of',
        ]);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id,
            'objective'       => 'x',
            'store_id'        => 'some-store-nobody-here-has-heard-of',
        ])->assertStatus(201);
    }

    public function test_engine_failure_leaves_no_row_claiming_to_run(): void
    {
        [$user, $conversation] = $this->actor();
        Http::fake(['engine.test/*' => Http::response('', 500)]);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(503);

        $session = LiveShoppingSession::first();
        $this->assertSame('failed', $session->status);
        $this->assertNull($session->engine_session_id);   // never invented
        $this->assertNull($session->active_slot);          // slot released
    }

    /**
     * A 2xx with no usable deadline would hold the customer's one active slot
     * forever, with nothing for the reconciler to enforce.
     */
    public function test_missing_expires_at_is_a_failed_create(): void
    {
        [$user, $conversation] = $this->actor();
        Http::fake(['engine.test/*' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1,
            'session' => [
                'id' => 'eng_1', 'conversation_id' => (string) $conversation->id,
                'store_id' => 'on', 'status' => 'running', 'latest_seq' => 0,
                'created_at' => now()->toIso8601String(), 'expires_at' => '',
            ],
        ]], 201)]);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(503);

        $this->assertNull(LiveShoppingSession::first()->active_slot);
    }

    /**
     * A mapped code, never the engine's own text: upstream error strings are
     * attacker-influencable and must not be rendered to a customer verbatim.
     */
    public function test_engine_refusal_is_a_422_with_a_mapped_message(): void
    {
        [$user, $conversation] = $this->actor();
        Http::fake(['engine.test/*' => Http::response([
            'ok' => false,
            'error' => ['code' => 'store_unsupported', 'message' => '<script>pwn</script>'],
        ], 400)]);

        $response = $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(422);

        $response->assertJsonPath('message', 'that store is not available for live shopping yet');
        $this->assertStringNotContainsString('script', $response->getContent());
    }

    /** An answer we cannot interpret is "unavailable", not the caller's fault. */
    public function test_unknown_schema_version_is_a_503(): void
    {
        [$user, $conversation] = $this->actor();
        Http::fake(['engine.test/*' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 99, 'session' => ['id' => 'eng_1'],
        ]], 201)]);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(503);
    }

    /** status must be exactly `running`: `pending` is not durable acceptance. */
    public function test_a_non_running_status_is_a_failed_create(): void
    {
        [$user, $conversation] = $this->actor();
        $this->engineOk(['conversation_id' => (string) $conversation->id, 'status' => 'pending']);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(503);

        $this->assertNull(LiveShoppingSession::first()->active_slot);
    }

    /** An unexpected field means we are not speaking the contract we think. */
    /**
     * The engine's deadline must land in the column as a real datetime.
     *
     * Regression: the CAS is a query-builder update, so Eloquent's `datetime`
     * cast never runs and the engine's RFC3339 string went to the driver
     * verbatim. SQLite is dynamically typed and stored "…T…Z" without
     * complaint; MySQL rejected it with 1292, which made EVERY successful
     * create a 500 in production while this suite stayed green. Asserting the
     * VALUE (not just a 201) is what makes this test driver-honest.
     */
    public function test_the_engine_deadline_persists_as_a_real_datetime(): void
    {
        [$user, $conversation] = $this->actor();
        $deadline = now()->addMinutes(10);
        $this->engineOk([
            'conversation_id' => (string) $conversation->id,
            'expires_at'      => $deadline->toIso8601String(),
        ]);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(201);

        $session = LiveShoppingSession::firstOrFail();

        // Round-trips through the DB as a datetime the driver understands...
        $this->assertNotNull($session->expires_at);
        $this->assertSame(
            $deadline->utc()->format('Y-m-d H:i:s'),
            $session->expires_at->utc()->format('Y-m-d H:i:s'),
        );

        // ...and is therefore comparable in SQL, which the reconciler depends on.
        $this->assertSame(1, LiveShoppingSession::where('expires_at', '>', now())->count());
    }

    /**
     * The schema intentionally uses TIMESTAMP(0), so engine milliseconds are
     * deterministically truncated rather than preserved. The public value is
     * still a valid RFC3339 timestamp and the stored value remains usable by
     * the reconciler's SQL deadline comparison.
     */
    public function test_a_fractional_engine_deadline_is_truncated_to_the_column_precision(): void
    {
        [$user, $conversation] = $this->actor();
        $this->engineOk([
            'conversation_id' => (string) $conversation->id,
            'expires_at'      => '2030-01-02T03:04:05.250Z',
        ]);

        $response = $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(201);

        $session = LiveShoppingSession::firstOrFail();
        $persisted = DB::table('live_shopping_sessions')
            ->where('id', $session->id)
            ->value('expires_at');

        $this->assertSame('2030-01-02 03:04:05', $persisted);
        $this->assertSame(
            1,
            LiveShoppingSession::where('expires_at', '>', '2030-01-02 03:04:04')->count(),
        );

        $publicDeadline = $response->json('data.expires_at');
        $this->assertMatchesRegularExpression(
            '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?(?:Z|[+-]\\d{2}:\\d{2})$/',
            $publicDeadline,
        );
        $this->assertSame('2030-01-02T03:04:05+00:00', $publicDeadline);
    }

    public function test_unexpected_session_keys_are_rejected(): void
    {
        [$user, $conversation] = $this->actor();
        $this->engineOk(['conversation_id' => (string) $conversation->id, 'surprise' => 'x']);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(503);
    }

    /**
     * The ENVELOPE is closed as well as the session inside it. An extra key out
     * here means the same thing it means in there: we are not speaking the
     * contract we think we are, and the safe reading of an unrecognised
     * envelope is "no usable answer", not "close enough".
     */
    public function test_unexpected_envelope_keys_are_rejected(): void
    {
        [$user, $conversation] = $this->actor();

        Http::fake(['engine.test/*' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1,
            'surprise'       => 'x',
            'session' => [
                'id'              => 'eng_1',
                'conversation_id' => (string) $conversation->id,
                'store_id'        => 'on',
                'status'          => 'running',
                'latest_seq'      => 5,
                'created_at'      => now()->toIso8601String(),
                'expires_at'      => now()->addMinutes(10)->toIso8601String(),
            ],
        ]], 201)]);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(503);

        // And it leaves no row claiming to run.
        $this->assertDatabaseMissing('live_shopping_sessions', ['status' => 'running']);
    }

    /** A past deadline is unusable: the reconciler would expire it immediately. */
    public function test_a_past_expires_at_is_a_failed_create(): void
    {
        [$user, $conversation] = $this->actor();
        $this->engineOk([
            'conversation_id' => (string) $conversation->id,
            'expires_at' => now()->subMinute()->toIso8601String(),
        ]);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(503);
    }

    public function test_second_concurrent_session_is_a_409(): void
    {
        [$user, $conversation] = $this->actor();
        $this->engineOk(['conversation_id' => (string) $conversation->id]);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(201);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'y', 'store_id' => 'on',
        ])->assertStatus(409);
    }

    /**
     * The constraint is the DATABASE's, not a precheck's. Insert the conflicting
     * row directly and prove the index refuses it.
     */
    public function test_the_database_refuses_a_second_active_slot(): void
    {
        [$user, $conversation] = $this->actor();

        LiveShoppingSession::create([
            'user_id' => $user->id, 'conversation_id' => $conversation->id,
            'status' => 'running', 'store_id' => 'on', 'stores' => [], 'objective' => 'x',
            'active_slot' => 1,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        LiveShoppingSession::create([
            'user_id' => $user->id, 'conversation_id' => $conversation->id,
            'status' => 'running', 'store_id' => 'on', 'stores' => [], 'objective' => 'y',
            'active_slot' => 1,
        ]);
    }

    /** Terminal sessions release the slot, so a NULL slot never collides. */
    public function test_a_finished_session_does_not_block_the_next_one(): void
    {
        [$user, $conversation] = $this->actor();

        LiveShoppingSession::create([
            'user_id' => $user->id, 'conversation_id' => $conversation->id,
            'status' => 'completed', 'store_id' => 'on', 'stores' => [], 'objective' => 'x',
            'active_slot' => null,
        ]);
        $this->engineOk(['conversation_id' => (string) $conversation->id]);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'y', 'store_id' => 'on',
        ])->assertStatus(201);
    }
}
