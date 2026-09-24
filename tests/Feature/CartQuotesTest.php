<?php

namespace Tests\Feature;

use App\Jobs\ProcessLiveShoppingResultJob;
use App\Jobs\QuoteStoreCartJob;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\LiveShoppingSession;
use App\Models\LiveShoppingWebhookReceipt;
use App\Models\PurchaseRequest;
use App\Models\StoreQuote;
use App\Models\User;
use App\Services\CartQuotes;
use App\Services\LiveShoppingEngine;
use App\Services\StoreQuoteInvoice;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\LiveShopping\Concerns\SignsDeliveries;
use Tests\LiveShoppingTestCase;

/**
 * C5 — Finalizar → a checkout quote per store → automatic invoice
 * (docs/C3_CART_SYNC_CONTRACT.md § C5, plan D9). The invoice sender is replaced
 * by a recorder: Stripe is never called here.
 */
class CartQuotesTest extends LiveShoppingTestCase
{
    use SignsDeliveries;

    private array $sent = [];
    private ?array $answer = null;
    /** @var array<int, array{pr: int, stores: array}> */
    private array $invoices = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_04_29_000007_add_team_to_users.php', '--force' => true]);
        Schema::create('purchase_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id');
            $t->foreignId('conversation_id')->nullable();
            $t->string('request_number')->unique();
            $t->string('status')->default('pending_review');
            $t->string('source')->default('assisted');
            $t->string('currency', 3)->default('usd');
            $t->text('customer_notes')->nullable();
            $t->text('admin_notes')->nullable();
            $t->string('stripe_invoice_id')->nullable();
            $t->timestamps();
        });
        Schema::create('purchase_request_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_request_id');
            $t->string('product_name');
            $t->text('product_url')->nullable();
            $t->text('product_image_url')->nullable();
            $t->decimal('price', 10, 2)->nullable();
            $t->integer('quantity')->default(1);
            $t->json('options')->nullable();
            $t->text('notes')->nullable();
            $t->string('stock_status')->nullable();
            $t->string('image_path')->nullable();
            $t->string('image_filename')->nullable();
            $t->string('image_mime_type')->nullable();
            $t->integer('image_size')->nullable();
            $t->text('image_url')->nullable();
            $t->timestamps();
        });
        foreach ([
            'database/migrations/2026_09_23_000000_create_carts_tables.php',
            'database/migrations/2026_09_23_010000_add_cart_to_live_shopping_sessions_table.php',
            'database/migrations/2026_09_24_000000_create_store_quotes_table.php',
            'database/migrations/2026_09_24_010000_add_boxly_lab_joined_at_to_users_table.php',
        ] as $path) {
            $this->artisan('migrate', ['--path' => $path, '--force' => true]);
        }
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->configureEngine([
            'cart_sync' => true, 'cart_quotes' => true, 'cart_sync_connection' => 'sync',
            'quote_max_store_usd' => 1500, 'quote_max_order_usd' => 3000,
        ]);
        config(['services.boxly_beta.emails' => ['tester@boxly.test']]);
        Mail::fake();

        // The invoice sender, recorded instead of Stripe; it moves the request to quoted like the real one.
        $this->app->instance(StoreQuoteInvoice::class, new class($this->invoices) extends StoreQuoteInvoice {
            public function __construct(private array &$log) {}
            public function send(PurchaseRequest $pr, Collection $quotes): void
            {
                $this->log[] = ['pr' => $pr->id, 'stores' => $quotes->pluck('total_cents', 'store_id')->all()];
                $pr->forceFill(['status' => PurchaseRequest::STATUS_QUOTED, 'stripe_invoice_id' => 'in_test_' . count($this->log)])->save();
            }
        });
    }

    private function fakeEngine(string $storeId = 'nike', ?array $answer = null, int $status = 201): void
    {
        $first = $this->answer === null;
        $this->answer = [$answer ?? ['ok' => true, 'data' => ['schema_version' => 1, 'session' => [
            'id' => "eng_q_{$storeId}", 'conversation_id' => '0', 'store_id' => $storeId, 'status' => 'running',
            'latest_seq' => 5, 'created_at' => now()->toIso8601String(), 'expires_at' => now()->addMinutes(12)->toIso8601String(), 'kind' => 'cart',
        ]]], $status];
        if ($first) {
            Http::fake(function (HttpRequest $request) {
                $this->sent[] = ['url' => $request->url(), 'body' => $request->body()];

                return Http::response(...$this->answer);
            });
        }
    }

    private function tester(): User
    {
        return User::factory()->createQuietly(['email' => 'tester@boxly.test']);
    }

    private function add(User $u, string $store, string $url, array $extra = []): void
    {
        $this->actingAs($u)->postJson('/cart/items', array_merge([
            'store_id' => $store, 'store_name' => ucfirst($store), 'product_url' => $url, 'title' => "Item {$store}",
            'price' => 50, 'source' => 'chat',
        ], $extra))->assertStatus(201);
    }

    private function quoteBlock(string $verdict = 'verified', ?int $total = 5728, array $over = []): array
    {
        return array_merge([
            'verdict' => $verdict, 'currency' => $verdict === 'failed' ? null : 'USD',
            'merchandise' => 5000, 'discounts' => 0, 'shipping' => 0, 'tax' => 388, 'fees' => 0, 'total' => $total,
            'estimated' => false, 'destination_verified' => $verdict !== 'failed', 'checkout_stage' => 'order_review',
            'evidence' => ['Order total $57.28'], 'observed_at' => now()->toIso8601String(),
        ], $over);
    }

    /** Deliver a quote terminal for a store's running quote session through the real webhook. */
    private function deliverQuote(StoreQuote $quote, array $block, int $n): void
    {
        $session = LiveShoppingSession::find($quote->live_shopping_session_id);
        $lines = CartItem::where('cart_id', $quote->cart_id)->where('store_id', $quote->store_id)->get()
            ->map(fn ($i) => ['selection_id' => 'ci-' . $i->id, 'state' => 'in_store_cart', 'availability' => 'in_stock', 'observed_quantity' => 1, 'note' => null])->all();
        $body = $this->body([
            'delivery_id' => "dlv_q{$n}", 'session_id' => $session->engine_session_id, 'conversation_id' => '0', 'products' => [],
            'result' => ['outcome' => 'completed', 'products' => [], 'error_code' => null,
                'cart' => ['cart_ref' => 'cart-' . $quote->cart_id, 'operation' => 'quote', 'lines' => $lines, 'quote' => $block]],
        ]);
        $r = $this->deliver($body, [], null, "nonce-q{$n}");
        $this->assertSame(202, $r->status(), $r->getContent());
        (new ProcessLiveShoppingResultJob(LiveShoppingWebhookReceipt::latest('id')->value('id')))->handle();
    }

    /** Finalize a two-store cart and run each store's quote job (engine faked). */
    private function finalizedTwoStores(): array
    {
        $u = $this->tester();
        Queue::fake();
        $this->add($u, 'nike', 'https://www.nike.com/t/a');
        $this->add($u, 'nike', 'https://www.nike.com/t/b');
        $this->add($u, 'gap', 'https://www.gap.com/p/c');
        $this->actingAs($u)->postJson('/cart/finalize')->assertStatus(201);
        Queue::assertPushed(QuoteStoreCartJob::class, 2);
        $this->sent = [];
        foreach (StoreQuote::orderBy('id')->get() as $q) {
            $this->fakeEngine($q->store_id);
            (new QuoteStoreCartJob($q->id))->withFakeQueueInteractions()->handle(app(LiveShoppingEngine::class));
        }

        return [$u, PurchaseRequest::first()];
    }

    public function test_finalize_by_a_tester_starts_one_quote_per_store_and_others_never_see_the_cart(): void
    {
        Queue::fake();
        // Boxly Lab: a customer outside the allowlist does not see the cart at all.
        $other = User::factory()->createQuietly(['email' => 'customer@example.com']);
        $this->actingAs($other)->getJson('/cart')->assertStatus(404);
        $this->actingAs($other)->postJson('/cart/items', ['store_id' => 'nike', 'product_url' => 'https://www.nike.com/t/a', 'title' => 'x', 'source' => 'chat'])->assertStatus(404);
        $this->actingAs($other)->postJson('/cart/finalize')->assertStatus(404);
        $this->assertSame(0, Cart::count());
        Queue::assertNotPushed(QuoteStoreCartJob::class);

        $u = $this->tester();
        $this->add($u, 'nike', 'https://www.nike.com/t/a');
        $this->add($u, 'gap', 'https://www.gap.com/p/c');
        $this->actingAs($u)->postJson('/cart/finalize')->assertStatus(201);
        $this->assertSame(['gap', 'nike'], StoreQuote::orderBy('store_id')->pluck('store_id')->all());
        Queue::assertPushed(QuoteStoreCartJob::class, fn ($job) => $job->connection === 'sync');
    }

    public function test_the_quote_job_asks_the_engine_for_a_quote_of_every_item_of_that_store(): void
    {
        $this->finalizedTwoStores();
        $bodies = collect($this->sent)->map(fn ($s) => json_decode($s['body'], true))->keyBy('store_id');
        $this->assertSame(['gap', 'nike'], $bodies->keys()->sort()->values()->all());
        $this->assertSame('quote', $bodies['nike']['cart']['operation']);
        $this->assertCount(2, $bodies['nike']['cart']['selections'], 'both Nike items in one quote');
        $this->assertCount(1, $bodies['gap']['cart']['selections']);
        $nike = StoreQuote::where('store_id', 'nike')->first();
        $this->assertSame(StoreQuote::STATUS_RUNNING, $nike->status);
        $session = LiveShoppingSession::find($nike->live_shopping_session_id);
        $this->assertSame(CartSync_activeKey($nike), $session->cart_active_key);
    }

    public function test_the_customer_payload_names_the_store_browser_of_a_running_quote_only_while_it_runs(): void
    {
        [, $pr] = $this->finalizedTwoStores();
        $gap = StoreQuote::where('store_id', 'gap')->first();
        $quotes = collect(StoreQuote::payloadFor($pr, false))->keyBy('store_id');
        $this->assertSame($gap->live_shopping_session_id, $quotes['gap']['live_session_id']);

        $this->deliverQuote($gap, $this->quoteBlock('verified', 3664), 1);
        $quotes = collect(StoreQuote::payloadFor($pr, false))->keyBy('store_id');
        $this->assertNull($quotes['gap']['live_session_id']);
    }

    public function test_every_store_verified_sends_one_automatic_invoice_with_each_store_total(): void
    {
        [, $pr] = $this->finalizedTwoStores();
        [$gap, $nike] = [StoreQuote::where('store_id', 'gap')->first(), StoreQuote::where('store_id', 'nike')->first()];
        $this->deliverQuote($gap, $this->quoteBlock('verified', 3664), 1);
        $this->assertSame([], $this->invoices, 'no invoice while a store is still quoting');
        $this->deliverQuote($nike, $this->quoteBlock('verified', 10774), 2);

        $this->assertCount(1, $this->invoices);
        $this->assertSame(['gap' => 3664, 'nike' => 10774], $this->invoices[0]['stores']);
        $this->assertSame(PurchaseRequest::STATUS_QUOTED, $pr->fresh()->status);
        $this->assertSame('in_store_cart', CartItem::where('store_id', 'nike')->first()->sync_status);

        CartQuotes::maybeInvoice($pr->id);
        $this->assertCount(1, $this->invoices, 'never a second invoice');
    }

    public function test_a_store_without_a_verified_total_leaves_the_request_for_the_manual_quote(): void
    {
        [, $pr] = $this->finalizedTwoStores();
        $this->deliverQuote(StoreQuote::where('store_id', 'gap')->first(), $this->quoteBlock('verified', 3664), 1);
        $this->deliverQuote(StoreQuote::where('store_id', 'nike')->first(), $this->quoteBlock('failed', null), 2);

        $this->assertSame([], $this->invoices);
        $pr = $pr->fresh();
        $this->assertSame(PurchaseRequest::STATUS_PENDING_REVIEW, $pr->status);
        $this->assertStringContainsString('[auto-quote]', (string) $pr->admin_notes);
        $this->assertStringContainsString('Nike', (string) $pr->admin_notes);
    }

    public function test_a_total_over_the_automatic_limit_goes_to_the_team(): void
    {
        [, $pr] = $this->finalizedTwoStores();
        $this->deliverQuote(StoreQuote::where('store_id', 'gap')->first(), $this->quoteBlock('verified', 3664), 1);
        $this->deliverQuote(StoreQuote::where('store_id', 'nike')->first(), $this->quoteBlock('verified', 160000), 2);

        $this->assertSame([], $this->invoices);
        $this->assertStringContainsString('límite', (string) $pr->fresh()->admin_notes);
    }

    public function test_customers_see_status_and_money_the_team_also_sees_evidence(): void
    {
        [, $pr] = $this->finalizedTwoStores();
        $this->deliverQuote(StoreQuote::where('store_id', 'gap')->first(), $this->quoteBlock('verified', 3664), 1);
        $customer = collect(StoreQuote::payloadFor($pr, false))->keyBy('store_id');
        $team = collect(StoreQuote::payloadFor($pr, true))->keyBy('store_id');
        $this->assertSame(3664, $customer['gap']['total_cents']);
        $this->assertSame('running', $customer['nike']['status']);
        $this->assertArrayNotHasKey('evidence', $customer['gap']);
        $this->assertSame(['Order total $57.28'], $team['gap']['evidence']);
    }

    public function test_the_lab_access_code_opts_an_account_in_and_nobody_else(): void
    {
        $u = User::factory()->createQuietly(['email' => 'teammate@example.com']);
        $this->actingAs($u)->getJson('/cart')->assertStatus(404);
        config(['services.boxly_beta.access_code' => 'lab-test-code']);
        $this->actingAs($u)->postJson('/lab/join')->assertStatus(403)->assertJsonPath('code', 'bad_lab_code');
        $this->actingAs($u)->postJson('/lab/join', ['code' => 'wrong'])->assertStatus(403);
        $this->assertNull($u->fresh()->boxly_lab_joined_at, 'no code, no Lab');
        $this->actingAs($u)->postJson('/lab/join', ['code' => 'lab-test-code'])->assertOk()->assertJsonPath('data.boxly_lab', true);
        $this->actingAs($u->fresh())->getJson('/cart')->assertOk();
        $this->assertNotNull($u->fresh()->boxly_lab_joined_at);
        $other = User::factory()->createQuietly(['email' => 'customer@example.com']);
        $this->actingAs($other)->getJson('/cart')->assertStatus(404);
    }

    public function test_the_browser_may_call_the_cart_and_the_lab_opt_in_cross_origin(): void
    {
        foreach (['/cart', '/cart/items', '/cart/finalize', '/lab/join'] as $path) {
            $this->call('OPTIONS', $path, [], [], [], [
                'HTTP_ORIGIN' => 'https://boxly.mx',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-xsrf-token',
            ])->assertHeader('Access-Control-Allow-Origin');
        }
    }

    public function test_a_malformed_quote_is_refused_by_the_contract(): void
    {
        $this->assertNull(LiveShoppingEngine::cartQuote($this->quoteBlock('verified', null)), 'verified without a total');
        $this->assertNull(LiveShoppingEngine::cartQuote($this->quoteBlock('verified', 5728, ['tax' => 3.88])), 'money must be integer cents');
        $this->assertNull(LiveShoppingEngine::cartQuote($this->quoteBlock('verified', 5728, ['destination_verified' => false])));
        $this->assertNotNull(LiveShoppingEngine::cartQuote($this->quoteBlock('failed', null)));
    }
}

function CartSync_activeKey(StoreQuote $q): string
{
    return \App\Services\CartSync::activeKey($q->cart_id, $q->store_id);
}
