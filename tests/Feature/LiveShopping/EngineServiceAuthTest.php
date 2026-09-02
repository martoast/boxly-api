<?php

namespace Tests\Feature\LiveShopping;

use App\Models\Conversation;
use App\Models\User;
use App\Support\LiveShoppingSignature;
use Illuminate\Support\Facades\Http;
use Tests\LiveShoppingTestCase;

/**
 * OUTBOUND service-plane auth (Laravel -> engine).
 *
 * This is the fixture the engine verifies against, so it is asserted field by
 * field rather than "a signature header exists". Canonical UTF-8 bytes:
 *
 *   v1\nMETHOD\nPATH\nTIMESTAMP\nNONCE\nBODY_SHA256
 *
 * The METHOD and PATH are inside the signature specifically so a captured body
 * cannot be replayed against a different endpoint.
 */
class EngineServiceAuthTest extends LiveShoppingTestCase
{
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

    private function create(): void
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);
        $this->engineOk($conversation);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(201);
    }

    /** No Bearer secret anywhere: the service plane is HMAC, not a bearer token. */
    public function test_no_bearer_token_is_sent(): void
    {
        $this->create();

        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
    }

    public function test_all_five_service_headers_are_present(): void
    {
        $this->create();

        Http::assertSent(function ($request) {
            foreach (['X-Boxly-Key-Id', 'X-Boxly-Timestamp', 'X-Boxly-Nonce',
                      'X-Boxly-Content-SHA256', 'X-Boxly-Signature'] as $header) {
                if (! $request->hasHeader($header)) {
                    return false;
                }
            }

            return $request->header('X-Boxly-Key-Id')[0] === 'svc-k1';
        });
    }

    /**
     * The signature must verify against the EXACT bytes sent. Re-encoding
     * between signing and sending is the classic way to ship a signature that
     * never verifies.
     */
    public function test_the_signature_verifies_over_the_exact_sent_bytes_method_and_path(): void
    {
        $this->create();

        Http::assertSent(function ($request) {
            $raw = $request->body();
            $timestamp = $request->header('X-Boxly-Timestamp')[0];
            $nonce = $request->header('X-Boxly-Nonce')[0];
            $sentHash = $request->header('X-Boxly-Content-SHA256')[0];

            // The hash header describes the bytes actually on the wire.
            $this->assertSame(hash('sha256', $raw), $sentHash);

            $canonical = LiveShoppingSignature::outboundCanonical(
                'POST', '/v1/sessions', $timestamp, $nonce, $sentHash,
            );

            return LiveShoppingSignature::matches(
                $request->header('X-Boxly-Signature')[0],
                $canonical,
                'service-secret',
            );
        });
    }

    /** A signature bound to /v1/sessions must not verify for another path. */
    public function test_the_signature_does_not_verify_for_a_different_path(): void
    {
        $this->create();

        Http::assertSent(function ($request) {
            $canonical = LiveShoppingSignature::outboundCanonical(
                'POST',
                '/v1/sessions/other/viewer-tickets',   // wrong path
                $request->header('X-Boxly-Timestamp')[0],
                $request->header('X-Boxly-Nonce')[0],
                $request->header('X-Boxly-Content-SHA256')[0],
            );

            return ! LiveShoppingSignature::matches(
                $request->header('X-Boxly-Signature')[0], $canonical, 'service-secret',
            );
        });
    }

    public function test_a_tampered_body_breaks_the_signature(): void
    {
        $this->create();

        Http::assertSent(function ($request) {
            $canonical = LiveShoppingSignature::outboundCanonical(
                'POST', '/v1/sessions',
                $request->header('X-Boxly-Timestamp')[0],
                $request->header('X-Boxly-Nonce')[0],
                hash('sha256', $request->body() . 'tampered'),
            );

            return ! LiveShoppingSignature::matches(
                $request->header('X-Boxly-Signature')[0], $canonical, 'service-secret',
            );
        });
    }

    public function test_the_timestamp_is_fresh_and_the_nonce_is_unique_per_request(): void
    {
        $this->create();
        $seen = [];

        Http::assertSent(function ($request) use (&$seen) {
            $timestamp = (int) $request->header('X-Boxly-Timestamp')[0];
            $this->assertLessThanOrEqual(5, abs(time() - $timestamp));

            $nonce = $request->header('X-Boxly-Nonce')[0];
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $nonce);
            $this->assertNotContains($nonce, $seen);
            $seen[] = $nonce;

            return true;
        });
    }

    public function test_the_body_is_the_frozen_create_shape(): void
    {
        $this->create();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            $this->assertSame(
                ['schema_version', 'conversation_id', 'store_id', 'query', 'callback_id'],
                array_keys($body),
            );
            // callback_id is a frozen literal, never composed from input.
            $this->assertSame('boxly-p1', $body['callback_id']);
            // Stringified across the boundary.
            $this->assertIsString($body['conversation_id']);

            return true;
        });
    }

    /** An engine id lands in a URL path, so it is encoded, never interpolated raw. */
    public function test_engine_session_ids_are_url_encoded_in_the_ticket_path(): void
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);
        $session = \App\Models\LiveShoppingSession::create([
            'user_id' => $user->id, 'conversation_id' => $conversation->id,
            'engine_session_id' => 'eng/../admin', 'status' => 'running',
            'store_id' => 'on', 'stores' => [], 'objective' => 'x', 'active_slot' => 1,
        ]);

        Http::fake(['engine.test/*' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1, 'ticket' => 't',
            'expires_at' => now()->addSeconds(30)->toIso8601String(),
            'sse_url' => 'https://engine.test/s', 'media_available' => true,
            'whep_url' => 'https://engine.test/w',
            'ice_servers' => [['urls' => 'stun:stun.test:3478']],
        ]], 200)]);

        $this->actingAs($user)->postJson("/live-shopping/sessions/{$session->id}/ticket")->assertOk();

        Http::assertSent(function ($request) {
            $this->assertStringContainsString('eng%2F..%2Fadmin', (string) $request->url());
            $this->assertStringNotContainsString('eng/../admin', (string) $request->url());

            return true;
        });
    }
}
