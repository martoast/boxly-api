<?php

namespace Tests\Feature;

use App\Jobs\ProcessLiveShoppingResultJob;
use App\Jobs\QuoteStoreCartJob;
use App\Mail\PurchaseRequestCreated;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\LiveShoppingSession;
use App\Models\LiveShoppingWebhookReceipt;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
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
            $t->decimal('total_usd', 10, 2)->nullable();
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
            'database/migrations/2026_10_03_000000_add_conversation_key_to_carts.php',
            'database/migrations/2026_09_23_010000_add_cart_to_live_shopping_sessions_table.php',
            'database/migrations/2026_09_24_000000_create_store_quotes_table.php',
            'database/migrations/2026_09_24_010000_add_boxly_lab_joined_at_to_users_table.php',
            'database/migrations/2026_09_30_010000_add_dispatched_at_to_store_quotes_table.php',
        ] as $path) {
            $this->artisan('migrate', ['--path' => $path, '--force' => true]);
        }
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->configureEngine([
            'cart_sync' => true, 'cart_sync_on_add' => true, 'cart_quotes' => true, 'cart_sync_connection' => 'sync', 'quote_parallel' => 1,
            'quote_max_store_usd' => 1500, 'quote_max_order_usd' => 3000,
        ]);
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
    private function deliverQuote(StoreQuote $quote, array $block, int $n, array $states = []): void
    {
        $this->runJob($quote);
        $quote = $quote->fresh();
        $session = LiveShoppingSession::find($quote->live_shopping_session_id);
        $lines = CartItem::where('cart_id', $quote->cart_id)->where('store_id', $quote->store_id)->get()
            ->map(fn ($i) => ['selection_id' => 'ci-' . $i->id, 'state' => $states[$i->id] ?? 'in_store_cart', 'availability' => 'in_stock', 'observed_quantity' => 1, 'note' => null])->all();
        $body = $this->body([
            'delivery_id' => "dlv_q{$n}", 'session_id' => $session->engine_session_id, 'conversation_id' => '0', 'products' => [],
            'result' => ['outcome' => 'completed', 'products' => [], 'error_code' => null,
                'cart' => ['cart_ref' => 'cart-' . $quote->cart_id, 'operation' => 'quote', 'lines' => $lines, 'quote' => $block]],
        ]);
        $r = $this->deliver($body, [], null, "nonce-q{$n}");
        $this->assertSame(202, $r->status(), $r->getContent());
        (new ProcessLiveShoppingResultJob(LiveShoppingWebhookReceipt::latest('id')->value('id')))->handle();
    }

    /** Run a pending quote's job against the faked engine (a no-op when it already runs). */
    private function runJob(StoreQuote $quote): void
    {
        if ($quote->fresh()->status === StoreQuote::STATUS_PENDING) {
            $this->fakeEngine($quote->store_id);
            (new QuoteStoreCartJob($quote->id))->withFakeQueueInteractions()->handle(app(LiveShoppingEngine::class));
        }
    }

    /** Finalize a two-store cart (nike added first) and run the first store's quote job (engine faked). */
    private function finalizedTwoStores(): array
    {
        $u = $this->tester();
        Queue::fake();
        $this->add($u, 'nike', 'https://www.nike.com/t/a');
        $this->add($u, 'nike', 'https://www.nike.com/t/b');
        $this->add($u, 'gap', 'https://www.gap.com/p/c');
        $this->actingAs($u)->postJson('/cart/finalize')->assertStatus(201);
        Queue::assertPushed(QuoteStoreCartJob::class, 1);
        $this->sent = [];
        $this->runJob(StoreQuote::orderBy('id')->first());

        return [$u, PurchaseRequest::first()];
    }

    public function test_finalize_starts_one_quote_per_store(): void
    {
        Queue::fake();
        $u = $this->tester();
        $this->add($u, 'nike', 'https://www.nike.com/t/a');
        $this->add($u, 'gap', 'https://www.gap.com/p/c');
        $this->actingAs($u)->postJson('/cart/finalize')->assertStatus(201);
        $this->assertSame(['gap', 'nike'], StoreQuote::orderBy('store_id')->pluck('store_id')->all());
        Queue::assertPushed(QuoteStoreCartJob::class, fn ($job) => $job->connection === 'sync');
    }

    /** A cart added gap, nike, gap: stores in the order of their first item, not by store id. */
    private function finalizedAddOrder(): PurchaseRequest
    {
        $u = $this->tester();
        Queue::fake();
        $this->add($u, 'nike', 'https://www.nike.com/t/a');
        $this->add($u, 'gap', 'https://www.gap.com/p/c');
        $this->add($u, 'adidas', 'https://www.adidas.com/p/d');
        $this->add($u, 'nike', 'https://www.nike.com/t/b');
        $this->actingAs($u)->postJson('/cart/finalize')->assertStatus(201);

        return PurchaseRequest::first();
    }

    private function settle(StoreQuote $q, string $status, ?string $error = null): void
    {
        $q->forceFill(['status' => $status, 'error_code' => $error, 'total_cents' => $status === 'failed' ? null : 1000])->save();
        Queue::fake();
        CartQuotes::afterQuoteSettled($q->fresh());
    }

    public function test_stores_are_quoted_in_the_order_they_were_added_and_only_the_first_starts(): void
    {
        $this->finalizedAddOrder();
        $this->assertSame(['nike', 'gap', 'adidas'], StoreQuote::orderBy('id')->pluck('store_id')->all());
        Queue::assertPushed(QuoteStoreCartJob::class, 1);
        Queue::assertPushed(QuoteStoreCartJob::class, fn ($j) => $j->storeQuoteId === StoreQuote::orderBy('id')->first()->id);
        $this->assertNotNull(StoreQuote::orderBy('id')->first()->dispatched_at);
        $this->assertSame(2, StoreQuote::whereNull('dispatched_at')->count());
    }

    public function test_every_terminal_advances_to_the_next_store(): void
    {
        foreach ([['verified', null], ['partial', null], ['failed', 'quote_unverified'], ['failed', 'no_quotable_items'], ['failed', 'gave_up_engine_busy']] as [$status, $err]) {
            StoreQuote::query()->delete();
            PurchaseRequest::query()->delete();
            CartItem::query()->delete();
            Cart::query()->delete();
            User::query()->delete();
            $pr = $this->finalizedAddOrder();
            [$nike, $gap] = [StoreQuote::orderBy('id')->first(), StoreQuote::orderBy('id')->skip(1)->first()];
            $this->settle($nike, $status, $err);
            Queue::assertPushed(QuoteStoreCartJob::class, 1);
            Queue::assertPushed(QuoteStoreCartJob::class, fn ($j) => $j->storeQuoteId === $gap->id);
        }
    }

    public function test_a_no_quotable_items_store_settles_through_the_job_and_moves_on(): void
    {
        $this->finalizedAddOrder();
        $nike = StoreQuote::orderBy('id')->first();
        CartItem::where('store_id', 'nike')->delete();
        Queue::fake();
        (new QuoteStoreCartJob($nike->id))->withFakeQueueInteractions()->handle(app(LiveShoppingEngine::class));
        $this->assertSame('no_quotable_items', $nike->fresh()->error_code);
        Queue::assertPushed(QuoteStoreCartJob::class, fn ($j) => $j->storeQuoteId === StoreQuote::where('store_id', 'gap')->value('id'));
    }

    public function test_replaying_after_quote_settled_dispatches_the_next_store_once(): void
    {
        $this->finalizedAddOrder();
        $nike = StoreQuote::orderBy('id')->first();
        $nike->forceFill(['status' => 'verified', 'total_cents' => 1000])->save();
        Queue::fake();
        CartQuotes::afterQuoteSettled($nike->fresh());
        CartQuotes::afterQuoteSettled($nike->fresh());
        CartQuotes::afterQuoteSettled($nike->fresh());
        Queue::assertPushed(QuoteStoreCartJob::class, 1);
    }

    public function test_nothing_is_dispatched_while_a_store_is_running(): void
    {
        $this->finalizedAddOrder();
        $nike = StoreQuote::orderBy('id')->first();
        $gap = StoreQuote::orderBy('id')->skip(1)->first();
        $nike->forceFill(['status' => 'running'])->save();
        Queue::fake();
        CartQuotes::afterQuoteSettled($gap);
        Queue::assertNothingPushed();
    }

    public function test_reconcile_sends_a_stalled_next_store_and_a_stale_one_but_not_a_fresh_one(): void
    {
        $this->finalizedAddOrder();
        [$nike, $gap] = [StoreQuote::orderBy('id')->first(), StoreQuote::orderBy('id')->skip(1)->first()];
        $nike->forceFill(['status' => 'verified', 'total_cents' => 1000])->save();   // crash before the dispatch

        Queue::fake();
        CartQuotes::reconcile();
        Queue::assertPushed(QuoteStoreCartJob::class, 1);
        Queue::assertPushed(QuoteStoreCartJob::class, fn ($j) => $j->storeQuoteId === $gap->id);

        Queue::fake();
        CartQuotes::reconcile();   // fresh: untouched
        Queue::assertNothingPushed();

        $gap->forceFill(['dispatched_at' => now()->subMinutes(11)])->save();
        Queue::fake();
        CartQuotes::reconcile();   // stale and still pending: sent again
        Queue::assertPushed(QuoteStoreCartJob::class, 1);
        $this->assertTrue($gap->fresh()->dispatched_at->gt(now()->subMinute()));

        $gap->forceFill(['status' => 'running'])->save();
        $gap->forceFill(['dispatched_at' => now()->subMinutes(30)])->save();
        Queue::fake();
        CartQuotes::reconcile();   // something is running: never a second store
        Queue::assertNothingPushed();
    }

    public function test_the_summary_waits_for_every_store_then_matches_the_invoice_money(): void
    {
        [$u, $pr] = $this->finalizedTwoStores();
        $this->assertNull(CartQuotes::checkoutSummary($pr));

        // gap verified at 3333 (15% = 499.95 -> 500), nike partial at 10001 (15% = 1500.15)
        $this->deliverQuote(StoreQuote::where('store_id', 'gap')->first(), $this->quoteBlock('verified', 3333, ['merchandise' => 3000, 'tax' => 333]), 1);
        $this->deliverQuote(StoreQuote::where('store_id', 'nike')->first(), $this->quoteBlock('partial', 10001, ['merchandise' => 9000, 'tax' => 1001]), 2);
        $summary = CartQuotes::checkoutSummary($pr->fresh());

        $totals = StoreQuoteInvoice::totals(StoreQuote::orderBy('id')->get());
        $this->assertSame(13334, $summary['stores_total_cents']);
        $this->assertSame(2000, $summary['commission_cents'], 'round(13334 * 0.15) = 2000');
        $this->assertSame($totals['fee_cents'], $summary['commission_cents']);
        $this->assertSame($totals['total_cents'], $summary['total_cents']);
        $this->assertEquals(15, $summary['commission_percent']);
        $this->assertSame(['nike', 'gap'], array_column($summary['stores'], 'store_id'));
        $this->assertSame(['partial', 'verified'], array_column($summary['stores'], 'status'));
        $this->assertSame([true, true], array_column($summary['stores'], 'included'));
        $this->assertCount(2, $summary['stores'][0]['lines']);
        $this->assertSame(['title', 'variants', 'quantity', 'unit_price_cents', 'state', 'image_url'], array_keys($summary['stores'][0]['lines'][0]));
        // each line carries its own photo for the invoice card (Alex 2026-10-03: "add the product image right there")
        $first = $summary['stores'][0];
        $this->assertSame(CartItem::where('store_id', $first['store_id'])->orderBy('id')->value('image_url'), $first['lines'][0]['image_url']);
        $this->assertSame(5000, $summary['stores'][0]['lines'][0]['unit_price_cents']);
        $this->assertSame('in_store_cart', $summary['stores'][0]['lines'][0]['state']);
        $this->assertSame(1001, $summary['stores'][0]['tax_cents']);
        $this->assertTrue($summary['invoiced'], 'the recorded invoice was sent');
        $this->assertNull($summary['invoice_total_cents'], 'the recorder left total_usd empty');
        $this->assertSame(PurchaseRequest::STATUS_QUOTED, $pr->fresh()->status);
    }

    public function test_a_failed_store_shows_the_customer_a_spanish_reason_never_the_error_code(): void
    {
        [, $pr] = $this->finalizedTwoStores();
        $this->deliverQuote(StoreQuote::where('store_id', 'nike')->first(), $this->quoteBlock('failed', null), 1);
        $this->deliverQuote(StoreQuote::where('store_id', 'gap')->first(), $this->quoteBlock('verified', 3664), 2);
        $rows = collect(StoreQuote::payloadFor($pr, false))->keyBy('store_id');
        $this->assertSame('No se pudo verificar el total en la tienda', $rows['nike']['reason']);
        $this->assertNull($rows['gap']['reason']);
        $this->assertArrayNotHasKey('error_code', $rows['nike']);
        $this->assertArrayNotHasKey('evidence', $rows['nike']);
        $summary = CartQuotes::checkoutSummary($pr->fresh());
        $this->assertSame($rows['nike']['reason'], $summary['stores'][0]['reason']);
    }

    public function test_a_dropped_store_is_in_the_summary_but_not_in_the_totals(): void
    {
        [$u, $pr] = $this->finalizedTwoStores();
        $this->deliverQuote(StoreQuote::where('store_id', 'gap')->first(), $this->quoteBlock('verified', 3664), 1);
        $this->deliverQuote(StoreQuote::where('store_id', 'nike')->first(), $this->quoteBlock('failed', null), 2);
        $pr->forceFill(['total_usd' => 42.14])->save();
        $summary = CartQuotes::checkoutSummary($pr->fresh());

        $this->assertSame([false, true], array_column($summary['stores'], 'included'));
        $this->assertSame('No se pudo verificar el total en la tienda', $summary['stores'][0]['reason']);
        $this->assertNull($summary['stores'][1]['reason']);
        $this->assertSame(3664, $summary['stores_total_cents']);
        $this->assertSame(550, $summary['commission_cents']);   // round(549.6)
        $this->assertSame(4214, $summary['total_cents']);
        $this->assertTrue($summary['invoiced']);
        $this->assertSame(4214, $summary['invoice_total_cents'], 'the invoiced total, from the request');
    }

    public function test_the_quote_job_asks_the_engine_for_a_quote_of_every_item_of_that_store(): void
    {
        $this->finalizedTwoStores();
        $this->runJob(StoreQuote::where('store_id', 'gap')->first());
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
        $nike = StoreQuote::where('store_id', 'nike')->first();
        $gap = StoreQuote::where('store_id', 'gap')->first();
        $quotes = collect(StoreQuote::payloadFor($pr, false))->keyBy('store_id');
        $this->assertSame($nike->live_shopping_session_id, $quotes['nike']['live_session_id']);
        $this->assertNull($quotes['gap']['live_session_id']);
        $this->runJob($gap);
        $quotes = collect(StoreQuote::payloadFor($pr, false))->keyBy('store_id');
        $this->assertNotNull($quotes['gap']['live_session_id']);

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
        $this->assertSame(['nike' => 10774, 'gap' => 3664], $this->invoices[0]['stores'], 'invoice stores in add order');
        $this->assertSame(PurchaseRequest::STATUS_QUOTED, $pr->fresh()->status);
        $this->assertSame('in_store_cart', CartItem::where('store_id', 'nike')->first()->sync_status);

        CartQuotes::maybeInvoice($pr->id);
        $this->assertCount(1, $this->invoices, 'never a second invoice');
        Mail::assertNotQueued(PurchaseRequestCreated::class, 'the invoice email is the customer\'s only email');
    }

    public function test_the_stores_that_verified_are_invoiced_and_a_failed_store_drops_out(): void
    {
        [, $pr] = $this->finalizedTwoStores();
        $this->deliverQuote(StoreQuote::where('store_id', 'gap')->first(), $this->quoteBlock('verified', 3664), 1);
        $this->deliverQuote(StoreQuote::where('store_id', 'nike')->first(), $this->quoteBlock('failed', null), 2);

        $this->assertCount(1, $this->invoices, 'Gap verified: the invoice goes out');
        $this->assertSame(['gap' => 3664], $this->invoices[0]['stores'], 'only the store that verified is billed');
        $nikeUrls = CartItem::where('store_id', 'nike')->pluck('product_url')->all();
        $this->assertNotEmpty($nikeUrls);
        foreach (PurchaseRequestItem::where('purchase_request_id', $pr->id)->whereIn('product_url', $nikeUrls)->get() as $item) {
            $this->assertSame(PurchaseRequestItem::STOCK_UNAVAILABLE, $item->stock_status, 'the failed store\'s items are not billed');
        }
        CartQuotes::maybeInvoice($pr->id);
        $this->assertCount(1, $this->invoices, 'never a second invoice');
    }

    public function test_no_store_verified_leaves_the_request_for_the_manual_quote(): void
    {
        [, $pr] = $this->finalizedTwoStores();
        $this->deliverQuote(StoreQuote::where('store_id', 'gap')->first(), $this->quoteBlock('failed', null), 1);
        $this->deliverQuote(StoreQuote::where('store_id', 'nike')->first(), $this->quoteBlock('failed', null), 2);

        $this->assertSame([], $this->invoices);
        $pr = $pr->fresh();
        $this->assertSame(PurchaseRequest::STATUS_PENDING_REVIEW, $pr->status);
        $this->assertStringContainsString('[auto-quote]', (string) $pr->admin_notes);
        $this->assertStringContainsString('Nike', (string) $pr->admin_notes);

        // The "request received" email was held at finalize; it goes out now, once.
        Mail::assertQueued(PurchaseRequestCreated::class, 1);
        CartQuotes::maybeInvoice($pr->id);
        Mail::assertQueued(PurchaseRequestCreated::class, 1);
    }

    public function test_the_test_account_never_alerts_the_shopping_team(): void
    {
        $this->assertFalse(\App\Services\PurchaseRequestIntake::alertsTeam(new User(['email' => 'AlexMartos96+BoxlyLab@gmail.com'])));
        $this->assertTrue(\App\Services\PurchaseRequestIntake::alertsTeam(new User(['email' => 'customer@example.com'])));
    }

    public function test_finalize_while_the_agent_quotes_sends_the_customer_no_email_yet(): void
    {
        $this->finalizedTwoStores();
        Mail::assertNotQueued(PurchaseRequestCreated::class);
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
        $this->assertNull($customer['gap']['reason']);
        $this->assertNull($customer['nike']['reason'], 'running: no reason');
        $this->assertArrayNotHasKey('error_code', $customer['gap']);
        $this->assertSame('running', $customer['nike']['status']);
        $this->assertArrayNotHasKey('evidence', $customer['gap']);
        $this->assertSame(['Order total $57.28'], $team['gap']['evidence']);
    }

    public function test_every_signed_in_customer_has_the_cart_and_guests_do_not(): void
    {
        // The Boxly Lab opt-in is gone (2026-09-28): the cart is the product, for any account, never for a guest.
        $u = User::factory()->createQuietly(['email' => 'customer@example.com']);
        $this->actingAs($u)->getJson('/cart')->assertOk();
        $this->actingAs($u)->postJson('/cart/items', ['store_id' => 'nike', 'product_url' => 'https://www.nike.com/t/a', 'title' => 'x', 'source' => 'chat'])->assertStatus(201);
        $this->app['auth']->forgetGuards();
        $this->getJson('/cart')->assertStatus(401);
    }

    public function test_the_browser_may_call_the_cart_cross_origin(): void
    {
        foreach (['/cart', '/cart/items', '/cart/finalize'] as $path) {
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

    public function test_customer_payload_rows_are_in_add_order_not_store_id_order(): void
    {
        $pr = $this->finalizedAddOrder();   // nike, gap, adidas
        $this->assertSame(['nike', 'gap', 'adidas'], array_column(StoreQuote::payloadFor($pr, false), 'store_id'));
        $this->assertSame(['nike', 'gap', 'adidas'], array_column(StoreQuote::payloadFor($pr, true), 'store_id'));
    }

    private function oldPendingRow(string $prStatus): StoreQuote
    {
        $pr = $this->finalizedAddOrder();
        $pr->forceFill(['status' => $prStatus])->save();
        StoreQuote::where('purchase_request_id', $pr->id)->where('id', '!=', StoreQuote::orderBy('id')->value('id'))->delete();
        $q = StoreQuote::orderBy('id')->first();
        $q->forceFill(['dispatched_at' => null, 'created_at' => now()->subDays(40)])->save();   // an old prod row

        return $q;
    }

    public function test_reconcile_never_revives_an_old_row_of_a_closed_request_but_does_recent_ones_of_live_ones(): void
    {
        $q = $this->oldPendingRow(PurchaseRequest::STATUS_CANCELLED);
        Queue::fake();
        CartQuotes::reconcile();
        Queue::assertNothingPushed();
        $this->assertNull($q->fresh()->dispatched_at);

        $q->purchaseRequest->forceFill(['status' => PurchaseRequest::STATUS_PENDING_REVIEW])->save();
        CartQuotes::reconcile();   // live request but the row is 40 days old and never dispatched
        Queue::assertNothingPushed();

        $q->forceFill(['created_at' => now()->subHour()])->save();
        CartQuotes::reconcile();
        Queue::assertPushed(QuoteStoreCartJob::class, 1);
    }

    public function test_claim_refuses_a_closed_request_without_touching_the_engine(): void
    {
        $pr = $this->finalizedAddOrder();
        $pr->forceFill(['status' => PurchaseRequest::STATUS_CANCELLED])->save();
        $nike = StoreQuote::orderBy('id')->first();
        $this->fakeEngine();
        $this->sent = [];
        Queue::fake();
        (new QuoteStoreCartJob($nike->id))->withFakeQueueInteractions()->handle(app(LiveShoppingEngine::class));
        $this->assertSame('failed', $nike->fresh()->status);
        $this->assertSame('request_closed', $nike->fresh()->error_code);
        $this->assertSame([], $this->sent);
        $this->assertSame(0, LiveShoppingSession::count());
        $this->assertSame([], $this->invoices);
        $this->assertSame(PurchaseRequest::STATUS_CANCELLED, $pr->fresh()->status);
    }

    public function test_a_missing_cart_fails_the_quote_and_advances(): void
    {
        $this->finalizedAddOrder();
        $nike = StoreQuote::orderBy('id')->first();
        Schema::disableForeignKeyConstraints();
        StoreQuote::where('id', $nike->id)->update(['cart_id' => 999999]);   // the cart is gone
        Schema::enableForeignKeyConstraints();
        Queue::fake();
        (new QuoteStoreCartJob($nike->id))->withFakeQueueInteractions()->handle(app(LiveShoppingEngine::class));
        $this->assertSame('cart_missing', $nike->fresh()->error_code);
        Queue::assertPushed(QuoteStoreCartJob::class, fn ($j) => $j->storeQuoteId === StoreQuote::where('store_id', 'gap')->value('id'));
    }

    public function test_a_retrying_job_refreshes_dispatched_at_so_reconcile_leaves_it_alone(): void
    {
        $this->finalizedAddOrder();
        $nike = StoreQuote::orderBy('id')->first();
        $nike->forceFill(['dispatched_at' => now()->subMinutes(30)])->save();
        $this->fakeEngine('nike', ['ok' => false, 'error' => ['code' => 'engine_busy']], 503);
        Queue::fake();
        (new QuoteStoreCartJob($nike->id))->withFakeQueueInteractions()->handle(app(LiveShoppingEngine::class));
        $nike = $nike->fresh();
        $this->assertSame('pending', $nike->status);
        $this->assertTrue($nike->dispatched_at->gt(now()->subMinute()), 'refreshed on the retry');
        Queue::fake();
        CartQuotes::reconcile();
        Queue::assertNothingPushed();
    }

    public function test_giving_up_does_not_clobber_a_row_a_duplicate_set_running(): void
    {
        $this->finalizedAddOrder();
        $nike = StoreQuote::orderBy('id')->first();
        $nike->forceFill(['status' => 'running'])->save();
        $job = (new QuoteStoreCartJob($nike->id))->withFakeQueueInteractions();
        $job->tries = 1;
        $call = new \ReflectionMethod($job, 'retryLater');
        Queue::fake();
        $call->invoke($job, 'engine_busy');
        $this->assertSame('running', $nike->fresh()->status);
        Queue::assertNothingPushed();

        $nike->forceFill(['status' => 'pending'])->save();
        $call->invoke($job, 'engine_busy');
        $this->assertSame('gave_up_engine_busy', $nike->fresh()->error_code);
        Queue::assertPushed(QuoteStoreCartJob::class, 1);   // advanced to the next store
    }

    public function test_dispatch_next_starts_exactly_one_store_when_called_repeatedly(): void
    {
        $this->finalizedAddOrder();
        StoreQuote::query()->update(['dispatched_at' => null]);
        Queue::fake();
        $id = PurchaseRequest::value('id');
        CartQuotes::dispatchNext($id);
        CartQuotes::dispatchNext($id);
        Queue::assertPushed(QuoteStoreCartJob::class, 1);
        $this->assertSame(1, StoreQuote::whereNotNull('dispatched_at')->count());
    }

    public function test_the_summary_tells_the_app_whether_the_invoice_is_automatic_or_manual(): void
    {
        [, $pr] = $this->finalizedTwoStores();
        $this->deliverQuote(StoreQuote::where('store_id', 'gap')->first(), $this->quoteBlock('verified', 3664), 1);
        $this->deliverQuote(StoreQuote::where('store_id', 'nike')->first(), $this->quoteBlock('verified', 5000), 2);
        $s = CartQuotes::checkoutSummary($pr->fresh());
        $this->assertSame('auto', $s['invoice_mode']);
        $this->assertNull($s['manual_reason']);
    }

    public function test_the_summary_is_manual_when_every_store_failed_or_a_total_is_over_the_limit(): void
    {
        [, $pr] = $this->finalizedTwoStores();
        $this->deliverQuote(StoreQuote::where('store_id', 'gap')->first(), $this->quoteBlock('failed', null), 1);
        $this->deliverQuote(StoreQuote::where('store_id', 'nike')->first(), $this->quoteBlock('failed', null), 2);
        $s = CartQuotes::checkoutSummary($pr->fresh());
        $this->assertSame('manual', $s['invoice_mode']);
        $this->assertSame('Nuestro equipo te confirmará el total', $s['manual_reason']);

        StoreQuote::query()->delete();
        PurchaseRequest::query()->delete();
        CartItem::query()->delete();
        Cart::query()->delete();
        User::query()->delete();
        [, $pr] = $this->finalizedTwoStores();
        $this->deliverQuote(StoreQuote::where('store_id', 'gap')->first(), $this->quoteBlock('verified', 3664), 3);
        $this->deliverQuote(StoreQuote::where('store_id', 'nike')->first(), $this->quoteBlock('verified', 160000), 4);
        $s = CartQuotes::checkoutSummary($pr->fresh());
        $this->assertSame('manual', $s['invoice_mode']);
        $this->assertStringNotContainsString('límite', (string) $s['manual_reason']);
    }

    public function test_a_partial_stores_unavailable_items_carry_state_unavailable_in_the_summary(): void
    {
        [, $pr] = $this->finalizedTwoStores();
        $b = CartItem::where('store_id', 'nike')->orderBy('id')->get()[1];
        $this->deliverQuote(StoreQuote::where('store_id', 'gap')->first(), $this->quoteBlock('verified', 3664), 1);
        $this->deliverQuote(StoreQuote::where('store_id', 'nike')->first(), $this->quoteBlock('partial', 5000), 2, [$b->id => 'unavailable']);
        $lines = CartQuotes::checkoutSummary($pr->fresh())['stores'][0]['lines'];
        $this->assertSame(['in_store_cart', 'unavailable'], array_column($lines, 'state'), 'state comes from the cart item sync_status');
    }

    // ── Alex 2026-10-03: adds wait in the Boxly cart; Finalizar builds the store carts, up to 2 at once ──

    public function test_an_add_never_starts_a_store_sync_by_default(): void
    {
        config(['services.live_shopping_engine.cart_sync_on_add' => false]);
        $u = $this->tester();
        Queue::fake();
        $r = $this->add($u, 'nike', 'https://www.nike.com/t/a');
        Queue::assertNotPushed(\App\Jobs\SyncStoreCartJob::class);
        $this->assertSame('pending', CartItem::first()->sync_status);
        $this->actingAs($u)->getJson('/cart')->assertJsonPath('data.sync_on_add', false);
        // and the stalled-line sweep never sends it either
        CartItem::query()->update(['updated_at' => now()->subMinutes(5)]);
        \App\Services\CartSync::redispatchStalled();
        Queue::assertNotPushed(\App\Jobs\SyncStoreCartJob::class);
    }

    public function test_finalizar_builds_two_stores_at_once_then_the_next(): void
    {
        config(['services.live_shopping_engine.quote_parallel' => 2, 'services.live_shopping_engine.cart_sync_on_add' => false]);
        $this->finalizedAddOrder();
        [$nike, $gap, $adidas] = StoreQuote::orderBy('id')->get()->all();
        Queue::assertPushed(QuoteStoreCartJob::class, 2);
        Queue::assertPushed(QuoteStoreCartJob::class, fn ($j) => $j->storeQuoteId === $nike->id);
        Queue::assertPushed(QuoteStoreCartJob::class, fn ($j) => $j->storeQuoteId === $gap->id);
        $this->assertNull($adidas->fresh()->dispatched_at);

        // the first to finish frees a slot: the third starts, and only it
        $this->settle($gap->fresh(), 'verified');
        Queue::assertPushed(QuoteStoreCartJob::class, 1);
        Queue::assertPushed(QuoteStoreCartJob::class, fn ($j) => $j->storeQuoteId === $adidas->id);
    }
}

function CartSync_activeKey(StoreQuote $q): string
{
    return \App\Services\CartSync::activeKey($q->cart_id, $q->store_id);
}
