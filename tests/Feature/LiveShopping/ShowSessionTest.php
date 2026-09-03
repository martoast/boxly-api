<?php

namespace Tests\Feature\LiveShopping;

use App\Models\Conversation;
use App\Models\LiveShoppingSession;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\LiveShoppingTestCase;

/**
 * Ownership on the READ route.
 *
 * `ticket` has its own 403 case, but `show` is a separate route with its own
 * call to authorizeOwner, and it is the one that renders `engine_session_id` —
 * the id another customer would need to validate (and therefore accept) a
 * session's EventV1 stream as their own. A regression here leaks that quietly:
 * the response still looks like a perfectly ordinary session object.
 */
class ShowSessionTest extends LiveShoppingTestCase
{
    private function makeSession(User $owner): LiveShoppingSession
    {
        $conversation = Conversation::create(['user_id' => $owner->id]);

        return LiveShoppingSession::create([
            'user_id'           => $owner->id,
            'conversation_id'   => $conversation->id,
            'engine_session_id' => 'eng_1',
            'status'            => LiveShoppingSession::STATUS_RUNNING,
            'store_id'          => 'on',
            'stores'            => [],
            'objective'         => 'x',
            'active_slot'       => 1,
        ]);
    }

    public function test_the_owner_sees_their_session(): void
    {
        $owner = User::factory()->createQuietly();
        $session = $this->makeSession($owner);

        $this->actingAs($owner)
            ->getJson("/live-shopping/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.engine_session_id', 'eng_1');
    }

    public function test_another_users_session_is_403_and_leaks_nothing(): void
    {
        $owner = User::factory()->createQuietly();
        $stranger = User::factory()->createQuietly();
        $session = $this->makeSession($owner);

        $response = $this->actingAs($stranger)
            ->getJson("/live-shopping/sessions/{$session->id}")
            ->assertStatus(403);

        // Not merely "not 200": the engine id must not appear anywhere in the
        // refusal body either.
        $this->assertStringNotContainsString('eng_1', $response->getContent());
    }

    public function test_an_unknown_session_is_404(): void
    {
        $user = User::factory()->createQuietly();

        $this->actingAs($user)
            ->getJson('/live-shopping/sessions/999999')
            ->assertStatus(404);
    }

    public function test_requires_authentication(): void
    {
        $owner = User::factory()->createQuietly();
        $session = $this->makeSession($owner);

        $this->getJson("/live-shopping/sessions/{$session->id}")
            ->assertStatus(401);
    }

    public function test_authoritative_engine_terminal_is_projected_and_releases_slot(): void
    {
        $owner = User::factory()->createQuietly();
        $session = $this->makeSession($owner);
        $product = [
            'store' => 'On', 'store_id' => 'on', 'title' => 'Cloudmonster',
            'url' => 'https://www.on.com/cloudmonster', 'image' => null,
            'current_price' => null, 'list_price' => null, 'availability' => 'unknown',
            'observed_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 60),
        ];
        Http::fake(['engine.test/*' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1, 'session' => [
                'id' => 'eng_1', 'conversation_id' => (string) $session->conversation_id,
                'store_id' => 'on', 'status' => 'completed', 'latest_seq' => 6,
                'media_status' => 'stopped', 'terminal_result' => [
                    'outcome' => 'completed', 'products' => [$product], 'error_code' => null,
                ], 'created_at' => now()->subMinute()->toIso8601String(),
                'updated_at' => now()->toIso8601String(), 'expires_at' => now()->addMinute()->toIso8601String(),
            ],
        ]], 200)]);

        $this->actingAs($owner)->getJson("/live-shopping/sessions/{$session->id}")
            ->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertNull($session->fresh()->active_slot);
        $this->assertSame(1, \App\Models\ConversationMessage::count());

        // A repeated status read is idempotent and cannot append a second part.
        $this->actingAs($owner)->getJson("/live-shopping/sessions/{$session->id}")->assertOk();
        $this->assertSame(1, \App\Models\ConversationMessage::count());
    }

    /** The status-reconcile projector persists the SAME part shape as the webhook: the caveat rides on it. */
    public function test_a_completed_partial_match_status_read_persists_the_caveat_on_the_part(): void
    {
        $owner = User::factory()->createQuietly();
        $session = $this->makeSession($owner);
        $product = [
            'store' => 'On', 'store_id' => 'on', 'title' => 'Cloudmonster',
            'url' => 'https://www.on.com/cloudmonster', 'image' => null,
            'current_price' => ['amount' => 149.99, 'currency' => 'USD'], 'list_price' => null, 'availability' => 'in_stock',
            'observed_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 60),
        ];
        Http::fake(['engine.test/*' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1, 'session' => [
                'id' => 'eng_1', 'conversation_id' => (string) $session->conversation_id,
                'store_id' => 'on', 'status' => 'completed', 'latest_seq' => 6,
                'media_status' => 'stopped', 'terminal_result' => [
                    'outcome' => 'completed', 'products' => [$product], 'error_code' => 'partial_match',
                ], 'created_at' => now()->subMinute()->toIso8601String(),
                'updated_at' => now()->toIso8601String(), 'expires_at' => now()->addMinute()->toIso8601String(),
            ],
        ]], 200)]);

        $this->actingAs($owner)->getJson("/live-shopping/sessions/{$session->id}")
            ->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.error_code', 'partial_match');
        $this->assertSame('partial_match', $session->fresh()->error_code);
        $message = \App\Models\ConversationMessage::query()->firstOrFail();
        $part = $message->content['parts'][0];
        $this->assertSame('tool-live_results', $part['type']);
        $this->assertSame('partial_match', $part['output']['caveat'] ?? null, 'the persisted gallery part carries the caveat');
        $this->assertCount(1, $part['output']['products']);
    }

    public function test_engine_status_mismatch_or_unavailable_leaves_local_row_unchanged(): void
    {
        $owner = User::factory()->createQuietly();
        $session = $this->makeSession($owner);
        Http::fake(['engine.test/*' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1, 'session' => [
                'id' => 'eng_1', 'conversation_id' => 'wrong', 'store_id' => 'on',
                'status' => 'failed', 'latest_seq' => 6, 'media_status' => 'stopped',
                'terminal_result' => ['outcome' => 'failed', 'products' => [], 'error_code' => 'store_blocked'],
                'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
                'expires_at' => now()->addMinute()->toIso8601String(),
            ],
        ]], 200)]);
        $this->actingAs($owner)->getJson("/live-shopping/sessions/{$session->id}")->assertOk();
        $this->assertSame('running', $session->fresh()->status);
        $this->assertSame(1, (int) $session->fresh()->active_slot);

        Http::fake(['engine.test/*' => Http::response([], 503)]);
        $this->actingAs($owner)->getJson("/live-shopping/sessions/{$session->id}")->assertOk();
        $this->assertSame('running', $session->fresh()->status);
    }
}
