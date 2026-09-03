<?php

namespace Tests\Feature\LiveShopping;

use App\Models\Conversation;
use App\Models\LiveShoppingSession;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\LiveShoppingTestCase;
use Tests\LiveShopping\Concerns\SignsDeliveries;

/**
 * Locks the EXACT public envelopes the frontend builds fixtures against.
 *
 * These assertions are deliberately about key sets rather than values: a field
 * quietly added or renamed here breaks Nuxt at runtime with no failure on this
 * side, which is precisely the drift this test exists to prevent.
 *
 * Note the two ID domains, which are NOT interchangeable:
 *   - `id`                (Laravel model id)  -> used for the Laravel ticket route
 *   - `engine_session_id` (engine's id)       -> used to validate EventV1.session_id
 * Confusing them means either a 404 on our routes or silently accepting another
 * session's events.
 */
class PublicContractTest extends LiveShoppingTestCase
{
    use SignsDeliveries;

    private function engineOk(Conversation $conversation): void
    {
        Http::fake(['engine.test/*' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1,
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
    }

    public function test_the_create_envelope_is_exact(): void
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);
        $this->engineOk($conversation);

        $response = $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(201);

        $this->assertSame(['success', 'data'], array_keys($response->json()));
        $this->assertTrue($response->json('success'));

        $this->assertSame([
            'id', 'status', 'engine_session_id', 'conversation_id',
            'store_id', 'expires_at', 'created_at', 'updated_at', 'error_code', 'stores',
        ], array_keys($response->json('data')));
        // L2 (multi-store): one entry per requested store, sharing the session's status before a terminal.
        $this->assertSame([['id' => 'on', 'status' => 'running', 'error_code' => null]], $response->json('data.stores'));

        // Both ID domains present and distinct.
        $this->assertIsInt($response->json('data.id'));
        $this->assertSame('eng_1', $response->json('data.engine_session_id'));
        $this->assertNotSame(
            (string) $response->json('data.id'),
            $response->json('data.engine_session_id'),
        );
    }

    public function test_the_show_envelope_matches_create(): void
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);
        $session = LiveShoppingSession::create([
            'user_id' => $user->id, 'conversation_id' => $conversation->id,
            'engine_session_id' => 'eng_1', 'status' => 'running', 'store_id' => 'on',
            'stores' => [], 'objective' => 'x', 'active_slot' => 1,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/live-shopping/sessions/{$session->id}")->assertOk();

        $this->assertSame([
            'id', 'status', 'engine_session_id', 'conversation_id',
            'store_id', 'expires_at', 'created_at', 'updated_at', 'error_code', 'stores',
        ], array_keys($response->json('data')));
        $this->assertSame([], $response->json('data.stores'), 'a row created with an empty stores list presents none');
    }

    public function test_the_ticket_envelope_is_exact_and_is_a_post(): void
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);
        $session = LiveShoppingSession::create([
            'user_id' => $user->id, 'conversation_id' => $conversation->id,
            'engine_session_id' => 'eng_1', 'status' => 'running', 'store_id' => 'on',
            'stores' => [], 'objective' => 'x', 'active_slot' => 1,
        ]);

        Http::fake(['engine.test/*' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1, 'ticket' => 'tkt',
            'expires_at' => now()->addSeconds(30)->toIso8601String(),
            'sse_url' => 'https://engine.test/s', 'media_available' => true,
            'whep_url' => 'https://engine.test/w',
            'ice_servers' => [['urls' => 'stun:stun.test:3478']],
        ]], 200)]);

        // GET must not be routable: a credential-minting call is not a link, so
        // it must never be cacheable, prefetchable or replayable from history.
        // (404 vs 405 is Laravel's business; what matters is that it is neither
        // served nor 200.)
        $getStatus = $this->actingAs($user)
            ->getJson("/live-shopping/sessions/{$session->id}/ticket")->getStatusCode();
        $this->assertContains($getStatus, [404, 405]);

        $response = $this->actingAs($user)
            ->postJson("/live-shopping/sessions/{$session->id}/ticket")->assertOk();

        $this->assertSame(['success', 'data'], array_keys($response->json()));
        $this->assertSame(
            ['ticket', 'expires_at', 'sse_url', 'media_available', 'whep_url', 'ice_servers'],
            array_keys($response->json('data')),
        );
        // No engine envelope leaks through.
        $this->assertArrayNotHasKey('ok', $response->json('data'));
        $this->assertArrayNotHasKey('schema_version', $response->json('data'));
    }

    /**
     * The public terminal reason is a GUARANTEED closed slug: only a failed
     * session exposes one, a hostile/absent stored value presents as the
     * literal 'failed', and every non-failed status presents null.
     */
    public function test_error_code_is_sanitized_failed_reasons_and_the_completed_caveat_only(): void
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);
        $session = LiveShoppingSession::create([
            'user_id' => $user->id, 'conversation_id' => $conversation->id,
            'engine_session_id' => 'eng_1', 'status' => 'running', 'store_id' => 'on',
            'stores' => [], 'objective' => 'x', 'active_slot' => 1,
        ]);
        $show = fn () => $this->actingAs($user)
            ->getJson("/live-shopping/sessions/{$session->id}")->json('data.error_code');

        // Non-failed statuses are null even if a code is somehow stored.
        $this->assertNull($show());
        $session->forceFill(['status' => 'completed', 'active_slot' => null, 'error_code' => 'store_blocked'])->save();
        $this->assertNull($show());

        // A COMPLETED session exposes exactly one caveat — the engine's
        // partial_match (a verified product that misses a requested constraint)
        // — and nothing else: a closed vocabulary, not a pass-through.
        $session->forceFill(['status' => 'completed', 'error_code' => 'partial_match'])->save();
        $this->assertSame('partial_match', $show());
        foreach (['PARTIAL_MATCH', 'partial_match!', 'partial', null] as $notTheCaveat) {
            $session->forceFill(['status' => 'completed', 'error_code' => $notTheCaveat])->save();
            $this->assertNull($show());
        }
        $session->forceFill(['status' => 'cancelled', 'error_code' => 'partial_match'])->save();
        $this->assertNull($show());

        // Failed with a clean machine slug passes through verbatim.
        $session->forceFill(['status' => 'failed', 'error_code' => 'store_blocked'])->save();
        $this->assertSame('store_blocked', $show());

        // The engine's rev-11 attribution codes are plain slugs too: the store's
        // own error page (store_error) and the neutral no-evidence ending
        // (verification_incomplete) both pass through unchanged, so the
        // frontend — not this API — decides the customer-facing words.
        foreach (['store_error', 'verification_incomplete'] as $code) {
            $session->forceFill(['status' => 'failed', 'error_code' => $code])->save();
            $this->assertSame($code, $show());
        }

        // Hostile, oversized, or absent stored values all present as 'failed'.
        foreach (["<script>alert(1)</script>", str_repeat('a', 41), 'Mixed-Case!', null] as $bad) {
            $session->forceFill(['error_code' => $bad])->save();
            $this->assertSame('failed', $show());
        }
    }

    /** The webhook ack is the ENGINE's envelope, not Boxly's. Deliberately. */
    public function test_the_webhook_ack_envelope_is_exact(): void
    {
        $response = $this->deliver($this->body())->assertStatus(202);

        $this->assertSame(['ok', 'data'], array_keys($response->json()));
        $this->assertSame(
            ['schema_version', 'delivery_id', 'accepted'],
            array_keys($response->json('data')),
        );
        $this->assertArrayNotHasKey('success', $response->json());
    }
}
