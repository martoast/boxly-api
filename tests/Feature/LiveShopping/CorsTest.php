<?php

namespace Tests\Feature\LiveShopping;

use App\Models\Conversation;
use App\Models\LiveShoppingSession;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\LiveShoppingTestCase;

/**
 * The ticket mint is the ONLY live-shopping call the BROWSER makes (create is
 * Nuxt-server-to-Laravel; the webhook is engine-to-Laravel). Without a CORS
 * path entry the SPA's cross-origin preflight got no CORS headers, the browser
 * never sent the real POST, and the customer silently lost the whole live
 * events surface. These tests pin the narrow browser plane open and everything
 * else exactly as closed as it was.
 */
class CorsTest extends LiveShoppingTestCase
{
    private const LOCAL_ORIGIN = 'http://127.0.0.1:13000';

    public function test_ticket_preflight_gets_credentialed_cors_for_the_local_origin(): void
    {
        $response = $this->call('OPTIONS', '/live-shopping/sessions/1/ticket', [], [], [], [
            'HTTP_ORIGIN' => self::LOCAL_ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-xsrf-token',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 204]);
        $this->assertSame(self::LOCAL_ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_session_show_preflight_is_also_a_browser_surface(): void
    {
        $response = $this->call('OPTIONS', '/live-shopping/sessions/1', [], [], [], [
            'HTTP_ORIGIN' => self::LOCAL_ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 204]);
        $this->assertSame(self::LOCAL_ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * The webhook is engine-to-Laravel, never a browser call: it must stay
     * OUTSIDE the CORS allowlist so no cross-origin page can be scripted
     * against it with ambient credentials.
     */
    public function test_the_webhook_path_gets_no_cors_headers(): void
    {
        $response = $this->call('OPTIONS', '/live-shopping/webhook', [], [], [], [
            'HTTP_ORIGIN' => self::LOCAL_ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    /** CORS opening the path must not have weakened AUTH on it. */
    public function test_an_unauthenticated_ticket_post_is_still_refused(): void
    {
        $this->postJson('/live-shopping/sessions/1/ticket', [], [
            'Origin' => self::LOCAL_ORIGIN,
        ])->assertStatus(401);
    }

    /**
     * Nonce-discrepancy pin: a RUNNING session's ticket call reaches the engine
     * client EXACTLY once; a terminal session answers 409 with ZERO engine
     * calls. This is what distinguishes "Laravel refused pre-engine" from
     * "engine never asked" in any future ledger investigation.
     */
    public function test_running_reaches_the_engine_once_and_terminal_makes_no_engine_call(): void
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);
        $session = LiveShoppingSession::create([
            'user_id' => $user->id, 'conversation_id' => $conversation->id,
            'engine_session_id' => 'eng_cors', 'status' => 'running',
            'store_id' => 'on', 'stores' => [], 'objective' => 'x', 'active_slot' => 1,
        ]);

        Http::fake(['engine.test/*' => Http::response(['ok' => true, 'data' => [
            'schema_version'  => 1,
            'ticket'          => 'tkt_cors',
            'expires_at'      => now()->addSeconds(55)->toIso8601String(),
            'sse_url'         => 'https://engine.test/sse/1',
            'media_available' => false,
            'whep_url'        => null,
            'ice_servers'     => [],
            'input_url'       => null,
        ]], 200)]);

        $this->actingAs($user)
            ->postJson("/live-shopping/sessions/{$session->id}/ticket")
            ->assertOk()
            ->assertJsonPath('data.media_available', false);
        Http::assertSentCount(1);

        $session->forceFill(['status' => 'completed', 'active_slot' => null])->save();

        $this->actingAs($user)
            ->postJson("/live-shopping/sessions/{$session->id}/ticket")
            ->assertStatus(409);
        Http::assertSentCount(1); // still exactly one — terminal never re-reaches the engine
    }
}
