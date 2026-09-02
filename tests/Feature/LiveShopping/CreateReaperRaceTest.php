<?php

namespace Tests\Feature\LiveShopping;

use App\Models\Conversation;
use App\Models\LiveShoppingSession;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\LiveShoppingTestCase;

/**
 * create() vs the reconciler, both interleavings.
 *
 * The row is inserted with active_slot=1 and NO deadline, then the engine call
 * happens. While that call is in flight the reconciler can legitimately expire
 * the row (a no-deadline row older than the call timeout is the
 * crashed-create case it exists to clean up).
 *
 * A blind ->save() of the engine's answer would then write status=running back
 * over a released row, producing a live session OUTSIDE the one-active
 * constraint — the invariant the whole feature rests on. Hence compare-and-set.
 */
class CreateReaperRaceTest extends LiveShoppingTestCase
{
    private function engineOkFor(Conversation $conversation): void
    {
        Http::fake([
            'engine.test/v1/sessions' => Http::response(['ok' => true, 'data' => [
                'schema_version' => 1,
                'session' => [
                    'id'              => 'eng_race',
                    'conversation_id' => (string) $conversation->id,
                    'store_id'        => 'on',
                    'status'          => 'running',
                    'latest_seq'      => 5,
                    'created_at'      => now()->toIso8601String(),
                    'expires_at'      => now()->addMinutes(10)->toIso8601String(),
                ],
            ]], 201),
            'engine.test/*' => Http::response(['ok' => true, 'data' => ['schema_version' => 1]], 200),
        ]);
    }

    /**
     * ORDER A — reaper wins. The engine accepted, but our row is already gone.
     * We must NOT resurrect it, must tell the engine to drop the session, and
     * must answer with a stable conflict.
     */
    public function test_when_the_reaper_wins_the_session_is_not_resurrected(): void
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);
        $this->engineOkFor($conversation);

        // Simulate the reaper firing mid-flight: as soon as the engine replies,
        // the row has already been expired and its slot released.
        Http::fake(function ($request) use ($conversation) {
            LiveShoppingSession::query()->update([
                'status'      => LiveShoppingSession::STATUS_FAILED,
                'error_code'  => 'expired',
                'active_slot' => null,
                'expires_at'  => null,
            ]);

            return Http::response(['ok' => true, 'data' => [
                'schema_version' => 1,
                'session' => [
                    'id' => 'eng_race', 'conversation_id' => (string) $conversation->id,
                    'store_id' => 'on', 'status' => 'running', 'latest_seq' => 5,
                    'created_at' => now()->toIso8601String(),
                    'expires_at' => now()->addMinutes(10)->toIso8601String(),
                ],
            ]], 201);
        });

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(409);

        $session = LiveShoppingSession::first();
        // Still the reaper's verdict — NOT resurrected as running.
        $this->assertSame('failed', $session->status);
        $this->assertSame('expired', $session->error_code);
        $this->assertNull($session->active_slot);
        $this->assertNull($session->engine_session_id);

        // And the engine was told to drop what it had accepted.
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/cancel'));
    }

    /**
     * ORDER B — create wins. The row gains a live deadline before the reconciler
     * locks it, so the reconciler must leave it alone. Re-checking only
     * isTerminal() would kill a session that had just come alive.
     */
    public function test_when_create_wins_the_reaper_leaves_the_live_session_alone(): void
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);
        $this->engineOkFor($conversation);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(201);

        // Backdate created_at so the no-deadline branch WOULD have matched, had
        // the deadline not landed first.
        $session = LiveShoppingSession::first();
        $session->forceFill(['created_at' => now()->subHour()])->save();

        $this->artisan('boxly:live-shopping-reconcile')->assertSuccessful();

        $session->refresh();
        $this->assertSame('running', $session->status);
        $this->assertSame(1, (int) $session->active_slot);
    }

    /** The customer is never left holding a slot they cannot see or use. */
    public function test_after_a_lost_race_the_customer_can_start_again(): void
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);

        Http::fake(function ($request) use ($conversation) {
            LiveShoppingSession::query()->update(['status' => 'failed', 'active_slot' => null]);

            return Http::response(['ok' => true, 'data' => [
                'schema_version' => 1,
                'session' => [
                    'id' => 'eng_race', 'conversation_id' => (string) $conversation->id,
                    'store_id' => 'on', 'status' => 'running', 'latest_seq' => 5,
                    'created_at' => now()->toIso8601String(),
                    'expires_at' => now()->addMinutes(10)->toIso8601String(),
                ],
            ]], 201);
        });

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(409);

        // No row holds the slot, so a fresh claim is possible.
        $this->assertSame(0, LiveShoppingSession::whereNotNull('active_slot')->count());
    }
}
