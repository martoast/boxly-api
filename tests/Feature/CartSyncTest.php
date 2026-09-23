<?php

namespace Tests\Feature;

use App\Jobs\ProcessLiveShoppingResultJob;
use App\Jobs\SyncStoreCartJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ConversationMessage;
use App\Models\LiveShoppingSession;
use App\Models\LiveShoppingWebhookReceipt;
use App\Models\User;
use App\Services\CartSync;
use App\Services\LiveShoppingEngine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\LiveShopping\Concerns\SignsDeliveries;
use Tests\LiveShoppingTestCase;

/**
 * C3 — the Boxly cart mirrored into the customer's real store cart through an
 * engine `cart` session (docs/C3_CART_SYNC_CONTRACT.md).
 *
 *   vendor/bin/phpunit tests/Feature/CartSyncTest.php
 */
class CartSyncTest extends LiveShoppingTestCase
{
    use SignsDeliveries;

    /** Every engine request the fake saw, decoded. */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Run the sync job inline so these tests see its engine call; production pins `database`.
        config(['services.live_shopping_engine.cart_sync_connection' => 'sync']);

        $this->artisan('migrate', [
            '--path'  => 'database/migrations/2026_04_29_000007_add_team_to_users.php',
            '--force' => true,
        ]);
        // carts.purchase_request_id points here; only the table has to exist.
        Schema::create('purchase_requests', function (Blueprint $t) {
            $t->id();
            $t->timestamps();
        });
        foreach ([
            'database/migrations/2026_09_23_000000_create_carts_tables.php',
            'database/migrations/2026_09_23_010000_add_cart_to_live_shopping_sessions_table.php',
        ] as $path) {
            $this->artisan('migrate', ['--path' => $path, '--force' => true]);
        }

        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->configureEngine(['cart_sync' => true]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function item(array $overrides = []): array
    {
        return array_merge([
            'store_id' => 'nike',
            'store_name' => 'Nike',
            'product_url' => 'https://www.nike.com/t/tech-fleece',
            'title' => 'Nike Tech Fleece',
            'price' => 130,
            'source' => 'chat',
            'variants' => ['size' => 'L'],
        ], $overrides);
    }

    private function engineSession(array $overrides = []): array
    {
        return array_merge([
            'id'              => 'eng_c1',
            'conversation_id' => '0',
            'store_id'        => 'nike',
            'status'          => 'running',
            'latest_seq'      => 5,
            'created_at'      => now()->toIso8601String(),
            'expires_at'      => now()->addMinutes(12)->toIso8601String(),
            'kind'            => 'cart',
        ], $overrides);
    }

    /** What the fake engine answers next (Http::fake stubs stack, so ONE stub reads this). */
    private ?array $answer = null;

    /** Fake the engine: every request answers $answer (default: an accepted cart create) and is recorded. */
    private function fakeEngine(?array $answer = null, int $status = 201): void
    {
        $this->sent = [];
        $first = $this->answer === null;
        $this->answer = [$answer ?? ['ok' => true, 'data' => [
            'schema_version' => 1, 'session' => $this->engineSession(),
        ]], $status];
        if ($first) {
            Http::fake(function (HttpRequest $request) {
                $this->sent[] = ['url' => $request->url(), 'body' => $request->body(), 'headers' => $request->headers()];

                return Http::response(...$this->answer);
            });
        }
    }

    private function engineBusy(): void
    {
        $this->fakeEngine(['ok' => false, 'error' => ['code' => 'engine_busy', 'retryable' => true]], 503);
    }

    /** A cart body the engine would deliver for a cart session. */
    private function cartDelivery(array $lines, array $overrides = []): array
    {
        return $this->body(array_merge([
            'session_id'      => 'eng_c1',
            'conversation_id' => '0',
            'products'        => [],
            'result'          => [
                'outcome' => 'completed', 'products' => [], 'error_code' => null,
                'cart'    => ['cart_ref' => 'cart-' . (Cart::value('id') ?? 1), 'operation' => 'add', 'lines' => $lines],
            ],
        ], $overrides));
    }

    private function line(CartItem $item, string $state = 'in_store_cart', ?string $note = null): array
    {
        return [
            'selection_id' => 'ci-' . $item->id, 'state' => $state,
            'availability' => $state === 'unavailable' ? 'out_of_stock' : 'in_stock',
            'observed_quantity' => $state === 'in_store_cart' ? 1 : 0, 'note' => $note,
        ];
    }

    /** Deliver through the real webhook, then run its projector inline. */
    private function deliverAndProcess(array $body): void
    {
        $this->deliver($body)->assertStatus(202);
        (new ProcessLiveShoppingResultJob(LiveShoppingWebhookReceipt::latest('id')->value('id')))->handle();
    }

    // ── tests ────────────────────────────────────────────────────────────

    public function test_flag_off_dispatches_nothing_and_changes_nothing(): void
    {
        $this->configureEngine(['cart_sync' => false]);
        Queue::fake();
        $this->fakeEngine();
        $u = User::factory()->createQuietly();

        $id = $this->actingAs($u)->postJson('/cart/items', $this->item())->assertStatus(201)->json('data.item.id');
        $this->actingAs($u)->postJson('/cart/items', $this->item())->assertStatus(201);
        $this->actingAs($u)->patchJson("/cart/items/{$id}", ['quantity' => 5])->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame([], $this->sent);
        $this->assertSame(0, LiveShoppingSession::count());
        $this->assertSame('pending', CartItem::find($id)->sync_status);
    }

    public function test_an_add_creates_a_cart_session_with_the_exact_contract_body(): void
    {
        $this->fakeEngine();
        $u = User::factory()->createQuietly();

        $this->actingAs($u)->postJson('/cart/items', $this->item())->assertStatus(201);

        $item = CartItem::first();
        $cart = Cart::first();
        $this->assertCount(1, $this->sent);
        $this->assertSame('https://engine.test/v1/sessions', $this->sent[0]['url']);
        $session = LiveShoppingSession::first();
        $this->assertSame(['live-shopping-session-' . $session->id], $this->sent[0]['headers']['Idempotency-Key']);
        $this->assertSame([
            'schema_version'  => 1,
            'conversation_id' => '0',
            'store_id'        => 'nike',
            'query'           => 'cart',
            'callback_id'     => 'boxly-p1',
            'kind'            => 'cart',
            'cart'            => [
                'cart_ref'     => 'cart-' . $cart->id,
                'customer_ref' => CartSync::customerRef($u->id),
                'operation'    => 'add',
                'selections'   => [[
                    'selection_id' => 'ci-' . $item->id,
                    'url'          => 'https://www.nike.com/t/tech-fleece',
                    'title'        => 'Nike Tech Fleece',
                    'quantity'     => 1,
                    'variants'     => ['size' => 'L'],
                ]],
            ],
        ], json_decode($this->sent[0]['body'], true));

        $this->assertSame('syncing', $item->sync_status);
        $this->assertSame('cart', $session->kind);
        $this->assertSame('running', $session->status);
        $this->assertSame('eng_c1', $session->engine_session_id);
        $this->assertSame($cart->id, (int) $session->cart_id);
        $this->assertSame($cart->id . ':nike', $session->cart_active_key);
        $this->assertNull($session->active_slot);
    }

    public function test_no_variants_is_sent_as_an_empty_object_and_a_chat_cart_sends_its_conversation(): void
    {
        $u = User::factory()->createQuietly();
        $conv = \App\Models\Conversation::create(['user_id' => $u->id, 'title' => 't']);
        $this->fakeEngine(['ok' => true, 'data' => ['schema_version' => 1,
            'session' => $this->engineSession(['conversation_id' => (string) $conv->id])]]);

        $this->actingAs($u)->postJson('/cart/items', $this->item(['variants' => [], 'conversation_id' => $conv->id]))->assertStatus(201);

        $this->assertStringContainsString('"variants":{}', $this->sent[0]['body']);
        $this->assertSame((string) $conv->id, json_decode($this->sent[0]['body'], true)['conversation_id']);
        $this->assertSame('syncing', CartItem::first()->sync_status);
    }

    public function test_engine_busy_puts_items_back_to_pending_and_releases_the_job_with_backoff(): void
    {
        // The shipped default is a real queue (setUp runs inline for the other tests).
        $shipped = (require config_path('services.php'))['live_shopping_engine']['cart_sync_connection'];
        $this->assertSame('database', $shipped);
        config(['services.live_shopping_engine.cart_sync_connection' => $shipped]);
        Queue::fake();
        $u = User::factory()->createQuietly();
        $this->actingAs($u)->postJson('/cart/items', $this->item())->assertStatus(201);
        // Never the default connection: on `sync` the busy release below would be a silent no-op.
        Queue::assertPushed(SyncStoreCartJob::class, fn (SyncStoreCartJob $j) => $j->connection === 'database');
        $this->engineBusy();

        $job = (new SyncStoreCartJob(Cart::first()->id, 'nike'))->withFakeQueueInteractions();
        $job->handle(app(LiveShoppingEngine::class));

        $job->assertReleased();
        $this->assertGreaterThanOrEqual(30, $job->job->releaseDelay);
        $this->assertLessThanOrEqual(60, $job->job->releaseDelay);
        $this->assertSame('pending', CartItem::first()->sync_status);
        $session = LiveShoppingSession::first();
        $this->assertSame('failed', $session->status);
        $this->assertSame('engine_busy', $session->error_code);
        $this->assertNull($session->cart_active_key);
    }

    public function test_engine_busy_on_the_last_attempt_leaves_items_pending_without_release(): void
    {
        Queue::fake();
        $u = User::factory()->createQuietly();
        $this->actingAs($u)->postJson('/cart/items', $this->item())->assertStatus(201);
        $this->engineBusy();

        $job = (new SyncStoreCartJob(Cart::first()->id, 'nike'))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;
        $job->handle(app(LiveShoppingEngine::class));

        $job->assertNotReleased();
        $this->assertSame('pending', CartItem::first()->sync_status);
    }

    public function test_a_second_add_while_a_sync_runs_makes_no_second_engine_call(): void
    {
        $this->fakeEngine();
        $u = User::factory()->createQuietly();

        $this->actingAs($u)->postJson('/cart/items', $this->item())->assertStatus(201);
        $this->actingAs($u)->postJson('/cart/items', $this->item(['product_url' => 'https://www.nike.com/t/other']))->assertStatus(201);

        $this->assertCount(1, $this->sent);
        $this->assertSame(1, LiveShoppingSession::count());
        $this->assertSame(['syncing', 'pending'], CartItem::orderBy('id')->pluck('sync_status')->all());
    }

    public function test_a_quantity_or_variant_change_marks_the_item_pending_and_dispatches(): void
    {
        Queue::fake();
        $u = User::factory()->createQuietly();
        $id = $this->actingAs($u)->postJson('/cart/items', $this->item())->json('data.item.id');
        CartItem::where('id', $id)->update(['sync_status' => 'in_store_cart']);

        $this->actingAs($u)->patchJson("/cart/items/{$id}", ['quantity' => 2])->assertOk()
            ->assertJsonPath('data.item.sync_status', 'pending');
        CartItem::where('id', $id)->update(['sync_status' => 'in_store_cart']);
        $this->actingAs($u)->patchJson("/cart/items/{$id}", ['variants' => ['size' => 'M']])->assertOk()
            ->assertJsonPath('data.item.sync_status', 'pending');
        CartItem::where('id', $id)->update(['sync_status' => 'in_store_cart']);
        // Unchanged values are not a change.
        $this->actingAs($u)->patchJson("/cart/items/{$id}", ['quantity' => 2])->assertOk()
            ->assertJsonPath('data.item.sync_status', 'in_store_cart');
        // A re-add of the same line is more units.
        $this->actingAs($u)->postJson('/cart/items', $this->item(['variants' => ['size' => 'M']]))->assertStatus(201)
            ->assertJsonPath('data.item.sync_status', 'pending');

        Queue::assertPushed(SyncStoreCartJob::class, 4);
    }

    public function test_a_cart_terminal_also_redispatches_other_stores_left_pending(): void
    {
        $this->fakeEngine();
        $u = User::factory()->createQuietly();
        $this->actingAs($u)->postJson('/cart/items', $this->item())->assertStatus(201);
        $a = CartItem::first();
        // Another store's item whose busy retries ran out while nike held the engine.
        $other = CartItem::create(array_merge($a->only(['cart_id', 'title', 'source', 'variants', 'variants_key']), [
            'store_id' => 'adidas', 'product_url' => 'https://www.adidas.com/us/samba',
            'product_url_hash' => CartItem::urlHash('https://www.adidas.com/us/samba'),
        ]));
        $this->assertSame('pending', $other->fresh()->sync_status);
        Queue::fake();

        $this->deliverAndProcess($this->cartDelivery([$this->line($a)]));

        $this->assertSame('in_store_cart', $a->fresh()->sync_status);
        Queue::assertPushed(SyncStoreCartJob::class, fn ($job) => $job->cartId === $a->cart_id && $job->storeId === 'adidas');
        Queue::assertNotPushed(SyncStoreCartJob::class, fn ($job) => $job->storeId === 'nike');
    }

    public function test_a_cart_terminal_updates_items_releases_the_key_and_redispatches_pending(): void
    {
        $this->fakeEngine();
        $u = User::factory()->createQuietly();
        $this->actingAs($u)->postJson('/cart/items', $this->item())->assertStatus(201);
        $this->actingAs($u)->postJson('/cart/items', $this->item(['product_url' => 'https://www.nike.com/t/other']))->assertStatus(201);
        [$a, $b] = CartItem::orderBy('id')->get()->all();
        // Pretend the session carried b too; c is added while it runs.
        CartItem::where('id', $b->id)->update(['sync_status' => 'syncing']);
        $c = CartItem::create(array_merge($a->only(['cart_id', 'store_id', 'title', 'source', 'variants', 'variants_key']), [
            'product_url' => 'https://www.nike.com/t/third', 'product_url_hash' => CartItem::urlHash('https://www.nike.com/t/third'),
        ]));
        Queue::fake();

        $this->deliverAndProcess($this->cartDelivery([
            $this->line($a),
            $this->line($b, 'unavailable', 'size L sold out'),
        ]));

        $this->assertSame('in_store_cart', $a->fresh()->sync_status);
        $this->assertNull($a->fresh()->sync_note);
        $this->assertSame('unavailable', $b->fresh()->sync_status);
        $this->assertSame('size L sold out', $b->fresh()->sync_note);
        $this->assertSame('pending', $c->fresh()->sync_status);
        $session = LiveShoppingSession::first();
        $this->assertSame('completed', $session->status);
        $this->assertNull($session->cart_active_key);
        $this->assertSame(0, ConversationMessage::count());
        Queue::assertPushed(SyncStoreCartJob::class, fn ($job) => $job->cartId === $a->cart_id && $job->storeId === 'nike');
    }

    public function test_a_syncing_item_the_result_does_not_mention_goes_back_to_pending(): void
    {
        $this->fakeEngine();
        $u = User::factory()->createQuietly();
        $this->actingAs($u)->postJson('/cart/items', $this->item())->assertStatus(201);
        Queue::fake();

        $this->deliverAndProcess($this->cartDelivery([]));

        $this->assertSame('pending', CartItem::first()->sync_status);
        Queue::assertPushed(SyncStoreCartJob::class, 1);
    }

    public function test_a_failed_terminal_fails_the_syncing_items_with_the_spanish_note(): void
    {
        $this->fakeEngine();
        $u = User::factory()->createQuietly();
        $this->actingAs($u)->postJson('/cart/items', $this->item())->assertStatus(201);
        Queue::fake();

        $this->deliverAndProcess($this->body([
            'session_id' => 'eng_c1', 'conversation_id' => '0', 'products' => [],
            'result' => ['outcome' => 'failed', 'products' => [], 'error_code' => 'worker_failed'],
        ]));

        $item = CartItem::first();
        $this->assertSame('failed', $item->sync_status);
        $this->assertSame('No se pudo agregar en la tienda; se reintentará', $item->sync_note);
        $session = LiveShoppingSession::first();
        $this->assertSame('failed', $session->status);
        $this->assertNull($session->cart_active_key);
        Queue::assertNotPushed(SyncStoreCartJob::class);
        $this->assertSame(0, ConversationMessage::count());
    }

    public function test_a_cart_result_is_rejected_for_a_non_cart_session(): void
    {
        $u = User::factory()->createQuietly();
        LiveShoppingSession::create([
            'user_id' => $u->id, 'engine_session_id' => 'eng_c1', 'status' => 'running', 'store_id' => 'nike',
            'kind' => 'manual', 'stores' => [], 'objective' => 'manual', 'active_slot' => 1, 'latest_seq' => 1,
        ]);

        $this->deliver($this->cartDelivery([]))->assertStatus(422);
        // And a malformed cart result is rejected outright.
        $this->deliver($this->cartDelivery([['selection_id' => 'x', 'state' => 'in_store_cart']], ['session_id' => 'eng_other']))
            ->assertStatus(422);
    }

    public function test_customer_ref_is_a_stable_keyed_hash_of_the_user_id(): void
    {
        $ref = CartSync::customerRef(42);

        $this->assertMatchesRegularExpression('/^cu-[a-f0-9]{32}$/', $ref);
        $this->assertSame($ref, CartSync::customerRef(42));
        $this->assertSame('cu-' . substr(hash_hmac('sha256', '42', config('app.key')), 0, 32), $ref);
        $this->assertNotSame($ref, CartSync::customerRef(43));
        config(['app.key' => 'another-key']);
        $this->assertNotSame($ref, CartSync::customerRef(42));
    }

    public function test_a_running_cart_sync_does_not_block_a_manual_session(): void
    {
        $this->fakeEngine();
        $u = User::factory()->createQuietly();
        $this->actingAs($u)->postJson('/cart/items', $this->item())->assertStatus(201);
        $this->fakeEngine(['ok' => true, 'data' => ['schema_version' => 1, 'session' => $this->engineSession([
            'id' => 'eng_m1', 'store_id' => 'on', 'kind' => 'manual',
        ])]]);

        $this->actingAs($u)->postJson('/live-shopping/sessions', ['kind' => 'manual', 'store_id' => 'on'])
            ->assertStatus(201);

        $this->assertNull(LiveShoppingSession::where('kind', 'cart')->value('active_slot'));
        $this->assertSame(1, (int) LiveShoppingSession::where('kind', 'manual')->value('active_slot'));
    }

    public function test_kind_cart_is_not_customer_selectable(): void
    {
        $u = User::factory()->createQuietly();

        $this->actingAs($u)->postJson('/live-shopping/sessions', ['kind' => 'cart', 'store_id' => 'nike'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_kind');
    }

    public function test_an_expired_cart_session_releases_its_key_and_settles_its_items(): void
    {
        $this->fakeEngine();
        $u = User::factory()->createQuietly();
        $this->actingAs($u)->postJson('/cart/items', $this->item())->assertStatus(201);
        LiveShoppingSession::query()->update(['expires_at' => now()->subMinutes(10)]);
        // The engine no longer answers for it.
        $this->fakeEngine(['ok' => false, 'error' => ['code' => 'unknown_session']], 404);

        $this->artisan('boxly:live-shopping-reconcile')->assertSuccessful();

        $session = LiveShoppingSession::first();
        $this->assertSame('failed', $session->status);
        $this->assertSame('expired', $session->error_code);
        $this->assertNull($session->cart_active_key);
        $this->assertSame('failed', CartItem::first()->sync_status);
        $this->assertSame(CartSync::FAILED_NOTE, CartItem::first()->sync_note);
    }

    public function test_a_lost_webhook_is_repaired_from_the_engine_status(): void
    {
        $this->fakeEngine();
        $u = User::factory()->createQuietly();
        $this->actingAs($u)->postJson('/cart/items', $this->item())->assertStatus(201);
        $item = CartItem::first();
        $session = LiveShoppingSession::first();
        $status = ['ok' => true, 'data' => ['schema_version' => 1, 'session' => [
            'id' => 'eng_c1', 'conversation_id' => '0', 'store_id' => 'nike', 'status' => 'completed',
            'latest_seq' => 9, 'media_status' => 'stopped', 'kind' => 'cart',
            'terminal_result' => ['outcome' => 'cart', 'products' => [], 'error_code' => null, 'cart' => [
                'cart_ref' => 'cart-' . $item->cart_id, 'operation' => 'add', 'lines' => [$this->line($item)],
            ]],
            'created_at' => now()->subMinute()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ]]];
        $this->fakeEngine($status, 200);

        // Through the owner's show route...
        $this->actingAs($u)->getJson("/live-shopping/sessions/{$session->id}")
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.kind', 'cart');

        $this->assertSame('in_store_cart', $item->fresh()->sync_status);
        $this->assertNull($session->fresh()->cart_active_key);
        $this->assertSame(0, ConversationMessage::count());
    }

    public function test_the_reconcile_command_repairs_a_lost_cart_webhook(): void
    {
        $this->fakeEngine();
        $u = User::factory()->createQuietly();
        $this->actingAs($u)->postJson('/cart/items', $this->item())->assertStatus(201);
        $this->fakeEngine(['ok' => true, 'data' => ['schema_version' => 1, 'session' => [
            'id' => 'eng_c1', 'conversation_id' => '0', 'store_id' => 'nike', 'status' => 'failed',
            'latest_seq' => 9, 'media_status' => 'stopped', 'kind' => 'cart',
            'terminal_result' => ['outcome' => 'failed', 'products' => [], 'error_code' => 'worker_failed'],
            'created_at' => now()->subMinute()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ]]], 200);

        $this->artisan('boxly:live-shopping-reconcile')->assertSuccessful();

        $this->assertSame('failed', LiveShoppingSession::first()->status);
        $this->assertNull(LiveShoppingSession::first()->cart_active_key);
        $this->assertSame('failed', CartItem::first()->sync_status);
    }

    public function test_get_cart_reports_whether_store_sync_is_on(): void
    {
        $user = User::factory()->createQuietly();
        config(['services.live_shopping_engine.cart_sync' => false]);
        $this->actingAs($user)->getJson('/cart')->assertOk()->assertJsonPath('data.sync_enabled', false);
        config(['services.live_shopping_engine.cart_sync' => true]);
        $this->actingAs($user)->getJson('/cart')->assertOk()->assertJsonPath('data.sync_enabled', true);
    }
}
