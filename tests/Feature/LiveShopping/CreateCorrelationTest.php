<?php

namespace Tests\Feature\LiveShopping;

use App\Models\Conversation;
use App\Models\LiveShoppingSession;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\LiveShoppingTestCase;

/**
 * The engine's answer must describe the session we actually asked for.
 *
 * This is the test that would catch a live stream being attached to the wrong
 * customer's thread. Nothing about status or expiry would ever catch it — the
 * response looks perfectly healthy.
 */
class CreateCorrelationTest extends LiveShoppingTestCase
{
    private function attempt(array $sessionOverrides): \Illuminate\Testing\TestResponse
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);

        Http::fake(['engine.test/*' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1,
            'session' => array_merge([
                'id'              => 'eng_1',
                'conversation_id' => (string) $conversation->id,
                'store_id'        => 'on',
                'status'          => 'running',
                'latest_seq'      => 5,
                'created_at'      => now()->toIso8601String(),
                'expires_at'      => now()->addMinutes(10)->toIso8601String(),
            ], $sessionOverrides),
        ]], 201)]);

        return $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id,
            'objective'       => 'x',
            'store_id'        => 'on',
        ]);
    }

    public function test_mismatched_conversation_id_is_a_failed_create(): void
    {
        $this->attempt(['conversation_id' => '999999'])->assertStatus(503);

        $session = LiveShoppingSession::first();
        $this->assertSame('failed', $session->status);
        $this->assertNull($session->engine_session_id);
        $this->assertNull($session->active_slot);
    }

    public function test_mismatched_store_id_is_a_failed_create(): void
    {
        $this->attempt(['store_id' => 'a-different-store'])->assertStatus(503);

        $session = LiveShoppingSession::first();
        $this->assertSame('failed', $session->status);
        $this->assertNull($session->engine_session_id);
        $this->assertNull($session->active_slot);
    }

    public function test_matching_correlation_succeeds(): void
    {
        $this->attempt([])->assertStatus(201);

        $this->assertSame('eng_1', LiveShoppingSession::first()->engine_session_id);
    }
}
