<?php

namespace Tests\Feature\LiveShopping;

use App\Models\Conversation;
use App\Models\LiveShoppingSession;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\LiveShoppingTestCase;

class TicketTest extends LiveShoppingTestCase
{
    private User $user;

    private LiveShoppingSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $this->user->id]);
        $this->session = LiveShoppingSession::create([
            'user_id' => $this->user->id, 'conversation_id' => $conversation->id,
            'engine_session_id' => 'eng_1', 'status' => 'running',
            'store_id' => 'on', 'stores' => [], 'objective' => 'x', 'active_slot' => 1,
        ]);
    }

    private function fakeTicket(array $overrides = [], int $status = 200): void
    {
        Http::fake(['engine.test/*' => Http::response(['ok' => true, 'data' => array_merge([
            'schema_version'  => 1,
            'ticket'          => 'tkt_abc',
            'expires_at'      => now()->addSeconds(45)->toIso8601String(),
            'sse_url'         => 'https://engine.test/sse/1',
            'media_available' => true,
            'whep_url'        => 'https://engine.test/whep/1',
            'ice_servers'     => [['urls' => 'stun:stun.test:3478']],
        ], $overrides)], $status)]);
    }

    public function test_returns_only_the_six_public_fields(): void
    {
        $this->fakeTicket();

        $response = $this->actingAs($this->user)
            ->postJson("/live-shopping/sessions/{$this->session->id}/ticket");

        $response->assertOk()->assertJsonPath('data.ticket', 'tkt_abc');

        // The envelope is unwrapped by the client and never forwarded.
        $data = $response->json('data');
        $this->assertSame(
            ['ticket', 'expires_at', 'sse_url', 'media_available', 'whep_url', 'ice_servers'],
            array_keys($data),
        );
        $this->assertTrue($data['media_available']);
    }

    /**
     * A missing publisher is normal degraded capability, not an error: the
     * events ticket still arrives, and the media fields are exactly null/[].
     */
    public function test_media_unavailable_still_returns_an_events_ticket(): void
    {
        $this->fakeTicket(['media_available' => false, 'whep_url' => null, 'ice_servers' => []]);

        $response = $this->actingAs($this->user)
            ->postJson("/live-shopping/sessions/{$this->session->id}/ticket");

        $response->assertOk()
            ->assertJsonPath('data.ticket', 'tkt_abc')
            ->assertJsonPath('data.media_available', false)
            ->assertJsonPath('data.whep_url', null)
            ->assertJsonPath('data.ice_servers', []);
        $this->assertSame('https://engine.test/sse/1', $response->json('data.sse_url'));
    }

    /**
     * The flag and the media fields must AGREE. A descriptor alongside
     * media_available:false (or the reverse) means we are not speaking the
     * contract we think we are — refuse, never coerce.
     */
    public function test_inconsistent_media_combinations_are_refused(): void
    {
        $inconsistent = [
            ['media_available' => false, 'whep_url' => 'https://engine.test/whep/1', 'ice_servers' => []],
            ['media_available' => false, 'whep_url' => null, 'ice_servers' => [['urls' => 'stun:stun.test:3478']]],
            ['media_available' => true, 'whep_url' => null, 'ice_servers' => [['urls' => 'stun:stun.test:3478']]],
            ['media_available' => true, 'whep_url' => 'https://engine.test/whep/1', 'ice_servers' => []],
            ['media_available' => 'yes'],
        ];

        foreach ($inconsistent as $overrides) {
            $this->fakeTicket($overrides);

            $this->actingAs($this->user)
                ->postJson("/live-shopping/sessions/{$this->session->id}/ticket")
                ->assertStatus(503);
        }
    }

    public function test_the_request_carries_the_user_id_and_read_only_scopes(): void
    {
        $this->fakeTicket();

        $this->actingAs($this->user)
            ->postJson("/live-shopping/sessions/{$this->session->id}/ticket")->assertOk();

        Http::assertSent(function ($request) {
            return $request['schema_version'] === 1
                && $request['user_id'] === (string) $this->user->id
                && $request['scopes'] === ['events:read', 'media:read'];
        });
    }

    public function test_the_ticket_is_never_persisted(): void
    {
        $this->fakeTicket();

        $this->actingAs($this->user)
            ->postJson("/live-shopping/sessions/{$this->session->id}/ticket")->assertOk();

        $this->assertStringNotContainsString(
            'tkt_abc',
            json_encode($this->session->fresh()->toArray()),
        );
    }

    public function test_another_users_session_is_403(): void
    {
        $this->fakeTicket();
        $other = User::factory()->createQuietly();

        $this->actingAs($other)
            ->postJson("/live-shopping/sessions/{$this->session->id}/ticket")
            ->assertStatus(403);
    }

    public function test_a_terminal_session_is_409_not_a_ticket_that_cannot_connect(): void
    {
        $this->fakeTicket();
        $this->session->forceFill(['status' => 'completed', 'active_slot' => null])->save();

        $this->actingAs($this->user)
            ->postJson("/live-shopping/sessions/{$this->session->id}/ticket")
            ->assertStatus(409);
    }

    public function test_unreachable_engine_is_503(): void
    {
        Http::fake(['engine.test/*' => Http::response('', 500)]);

        $this->actingAs($this->user)
            ->postJson("/live-shopping/sessions/{$this->session->id}/ticket")
            ->assertStatus(503);
    }

    public function test_retryable_media_unavailable_remains_a_viewer_specific_503(): void
    {
        Http::fake(['engine.test/*' => Http::response([
            'ok' => false,
            'error' => [
                'code' => 'media_unavailable',
                'message' => 'upstream prose must never reach the customer',
                'retryable' => true,
            ],
        ], 503)]);
        $before = $this->session->fresh()->only([
            'status', 'active_slot', 'engine_session_id', 'conversation_id',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/live-shopping/sessions/{$this->session->id}/ticket");

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'the live viewer is unavailable right now');
        $this->assertStringNotContainsString('upstream prose', $response->getContent());
        $this->assertSame($before, $this->session->fresh()->only(array_keys($before)));
        $this->assertSame(0, $this->session->conversation()->firstOrFail()->messages()->count());
    }

    public function test_contradictory_media_unavailable_envelopes_remain_refusals(): void
    {
        Http::fake(['engine.test/*' => Http::sequence()
            ->push([
                'ok' => false,
                'error' => [
                    'code' => 'media_unavailable',
                    'message' => 'untrusted upstream prose',
                    'retryable' => true,
                ],
            ], 422)
            ->push([
                'ok' => false,
                'error' => [
                    'code' => 'media_unavailable',
                    'message' => 'untrusted upstream prose',
                    'retryable' => false,
                ],
            ], 503),
        ]);

        foreach ([422, 422] as $expectedStatus) {
            $this->actingAs($this->user)
                ->postJson("/live-shopping/sessions/{$this->session->id}/ticket")
                ->assertStatus($expectedStatus)
                ->assertJsonPath('message', 'the live viewer is unavailable right now');
        }
    }

    /**
     * Never a partial ticket: a viewer missing its ICE servers connects to
     * nothing, which to the customer looks exactly like a working session.
     */
    public function test_a_ticket_missing_ice_servers_is_503(): void
    {
        Http::fake(['engine.test/*' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1,
            'ticket' => 't', 'expires_at' => now()->addSeconds(30)->toIso8601String(),
            'sse_url' => 'https://e/s', 'media_available' => true, 'whep_url' => 'https://e/w',
        ]], 200)]);

        $this->actingAs($this->user)
            ->postJson("/live-shopping/sessions/{$this->session->id}/ticket")
            ->assertStatus(503);
    }

    /** >60s is the engine misbehaving. Clamping it silently would hide that. */
    public function test_an_over_long_lifetime_is_rejected_not_clamped(): void
    {
        $this->fakeTicket(['expires_at' => now()->addMinutes(30)->toIso8601String()]);

        $this->actingAs($this->user)
            ->postJson("/live-shopping/sessions/{$this->session->id}/ticket")
            ->assertStatus(503);
    }

    /**
     * The engine deliberately mints 55s tickets — 5s below our 60s ceiling — so
     * a slightly fast engine clock on another host cannot make every mint fail.
     * 55s must be accepted; anything past the 60s ceiling stays rejected, and
     * the ceiling itself is NOT widened.
     */
    public function test_the_engines_55s_lifetime_is_accepted(): void
    {
        $this->fakeTicket(['expires_at' => now()->addSeconds(55)->toIso8601String()]);
        $this->actingAs($this->user)
            ->postJson("/live-shopping/sessions/{$this->session->id}/ticket")
            ->assertOk();
    }

    public function test_the_60s_ceiling_holds_just_past_the_boundary(): void
    {
        $this->fakeTicket(['expires_at' => now()->addSeconds(61)->toIso8601String()]);
        $this->actingAs($this->user)
            ->postJson("/live-shopping/sessions/{$this->session->id}/ticket")
            ->assertStatus(503);
    }
}
