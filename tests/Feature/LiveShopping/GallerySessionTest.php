<?php

namespace Tests\Feature\LiveShopping;

use App\Jobs\ProcessLiveShoppingResultJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\LiveShoppingSession;
use App\Models\LiveShoppingWebhookReceipt;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\LiveShoppingTestCase;

/**
 * The chat's live product gallery (2026-09-28): a shopper's product request starts an AGENT session — the engine
 * opens the store(s) in live browsers and builds the gallery from their own search — and its products land in the
 * conversation as the `tool-live_results` part the chat renders as its gallery. It was Boxly Lab only for its first
 * day; now it is every signed-in shopper's product search, and a guest still cannot start one.
 */
class GallerySessionTest extends LiveShoppingTestCase
{
    private function engine(int $cap = 3, string $conversationId = '1'): void
    {
        Http::fake([
            'engine.test/v1/catalog' => Http::response(['ok' => true, 'data' => [
                'schema_version' => 1, 'max_stores_per_session' => $cap,
                'stores' => [['id' => 'on', 'name' => 'On'], ['id' => 'new-balance', 'name' => 'New Balance'], ['id' => 'nike', 'name' => 'Nike']],
            ]], 200),
            'engine.test/v1/sessions' => Http::response(['ok' => true, 'data' => [
                'schema_version' => 1,
                'session' => [
                    'id' => 'eng_g1', 'conversation_id' => $conversationId, 'store_id' => 'on', 'status' => 'running', 'latest_seq' => 3,
                    'created_at' => now()->toIso8601String(), 'expires_at' => now()->addMinutes(10)->toIso8601String(),
                ],
            ]], 201),
        ]);
    }

    private function member(): array
    {
        $user = User::factory()->createQuietly(['email' => 'customer@example.com']);

        return [$user, Conversation::create(['user_id' => $user->id, 'title' => 't'])];
    }

    public function test_any_signed_in_shopper_starts_a_live_search_but_a_guest_cannot(): void
    {
        [$user, $conversation] = $this->member();
        $this->engine(3, (string) $conversation->id);
        $this->postJson('/live-shopping/sessions', ['objective' => 'running shoes', 'store_id' => 'on'])->assertStatus(401);
        $this->assertSame(0, LiveShoppingSession::count());
        Http::assertNothingSent();

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'running shoes', 'store_id' => 'on',
        ])->assertStatus(201)->assertJsonPath('data.kind', 'agent');
    }

    public function test_the_manual_store_browser_stays_open_to_everyone(): void
    {
        $user = User::factory()->createQuietly();
        $this->engine(3, '0'); // a manual session carries no conversation

        $this->actingAs($user)->postJson('/live-shopping/sessions', ['kind' => 'manual', 'store_id' => 'on'])->assertStatus(201);
    }

    public function test_a_request_fans_out_across_stores_up_to_the_engine_cap(): void
    {
        [$user, $conversation] = $this->member();
        $this->engine(3, (string) $conversation->id);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'running shoes',
            'store_id' => 'on', 'store_ids' => ['on', 'new-balance', 'nike'],
        ])->assertStatus(201)
            ->assertJsonPath('data.kind', 'agent')
            ->assertJsonPath('data.stores.2.id', 'nike');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/sessions')
            && $r['query'] === 'running shoes' && $r['store_ids'] === ['on', 'new-balance', 'nike'] && ! isset($r['kind']));
    }

    public function test_past_the_advertised_cap_the_request_is_refused_before_the_engine_is_asked(): void
    {
        [$user, $conversation] = $this->member();
        $this->engine(2);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'running shoes',
            'store_id' => 'on', 'store_ids' => ['on', 'new-balance', 'nike'],
        ])->assertStatus(422)->assertJsonPath('code', 'too_many_stores');

        $this->assertSame(0, LiveShoppingSession::count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/v1/sessions'));
    }

    public function test_the_gallery_lands_in_the_conversation_as_a_live_results_part_the_registry_prices(): void
    {
        [$user, $conversation] = $this->member();
        $session = LiveShoppingSession::create([
            'user_id' => $user->id, 'conversation_id' => $conversation->id, 'status' => LiveShoppingSession::STATUS_RUNNING,
            'store_id' => 'on', 'kind' => 'agent', 'stores' => [['id' => 'on']], 'objective' => 'running shoes',
            'engine_session_id' => 'eng_g1', 'latest_seq' => 3, 'active_slot' => 1, 'expires_at' => now()->addMinutes(10),
        ]);
        // A gallery card as the engine maps it: USD money, stock "unknown" (a grid never proves stock).
        $product = [
            'store' => 'On', 'store_id' => 'on', 'title' => 'Cloudrunner 3', 'url' => 'https://www.on.com/en-us/products/cr3',
            'image' => 'https://images.on.com/cr3.jpg', 'current_price' => ['amount' => 160, 'currency' => 'USD'],
            'list_price' => ['amount' => 180, 'currency' => 'USD'], 'availability' => 'unknown', 'observed_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
        $receipt = LiveShoppingWebhookReceipt::create([
            'delivery_id' => 'd-g1', 'content_sha256' => str_repeat('a', 64), 'status' => LiveShoppingWebhookReceipt::STATUS_RECEIVED,
            'terminal_seq' => 9, 'outcome' => 'completed', 'received_at' => now(),
            'payload' => [
                'delivery_id' => 'd-g1', 'session_id' => 'eng_g1', 'conversation_id' => (string) $conversation->id, 'terminal_seq' => 9,
                'result' => ['outcome' => 'completed', 'products' => [$product], 'error_code' => null],
                'assistant_part' => ['type' => 'tool-live_results', 'state' => 'output-available', 'output' => ['products' => [$product]]],
            ],
        ]);

        (new ProcessLiveShoppingResultJob($receipt->id))->handle();

        $this->assertSame('completed', $session->fresh()->status);
        $this->assertNull($session->fresh()->active_slot, 'the slot is free for the next request');
        $message = ConversationMessage::where('conversation_id', $conversation->id)->first();
        $this->assertSame('tool-live_results', $message->content['parts'][0]['type']);

        // The chat's product registry (what add-to-box binds by saved_id) reads it with a scalar price and store id.
        $registry = $this->actingAs($user)->getJson('/conversations/' . $conversation->id)->assertStatus(200)->json('data.products');
        $this->assertSame('Cloudrunner 3', $registry[0]['title']);
        $this->assertSame('on', $registry[0]['store_id']);
        $this->assertSame('https://www.on.com/en-us/products/cr3', $registry[0]['url']);
        $this->assertNotNull($registry[0]['price']);
        $this->assertTrue((bool) $registry[0]['on_sale']);
    }
}
