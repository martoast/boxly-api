<?php

namespace Tests\Feature\LiveShopping;

use App\Models\Conversation;
use App\Models\LiveShoppingSession;
use App\Models\User;
use App\Services\LiveShoppingEngine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\LiveShoppingTestCase;

/**
 * The engine's catalog is the ONLY routing source, and a store the engine does
 * not know is an actionable refusal — never a generic outage.
 */
class StoresTest extends LiveShoppingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(LiveShoppingEngine::CATALOG_CACHE_KEY);
    }

    public function test_stores_route_serves_the_engine_catalog_in_the_closed_shape(): void
    {
        $user = User::factory()->createQuietly();
        Http::fake(['engine.test/v1/catalog' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1,
            'stores'         => [
                ['id' => 'target', 'name' => 'Target', 'url' => 'https://www.target.com/'],
                ['id' => 'walmart', 'name' => ' Walmart ', 'url' => 'https://www.walmart.com/'],
                ['id' => 'acme-outlet', 'name' => 'Acme Outlet', 'url' => 'https://shop.acme-outlet.example/'],
            ],
        ]], 200)]);

        // A store this repo never names flows through exactly like the others.
        $this->actingAs($user)->getJson('/live-shopping/stores')
            ->assertStatus(200)
            ->assertExactJson(['success' => true, 'stores' => [
                ['id' => 'target', 'name' => 'Target', 'url' => 'https://www.target.com/'],
                ['id' => 'walmart', 'name' => 'Walmart', 'url' => 'https://www.walmart.com/'],
                ['id' => 'acme-outlet', 'name' => 'Acme Outlet', 'url' => 'https://shop.acme-outlet.example/'],
            ], 'max_stores_per_session' => 1]); // L2: an engine that advertises no cap opens one store per session

        // Cached: a second read does not call the engine again.
        Http::fake(['engine.test/v1/catalog' => Http::response(['ok' => false], 500)]);
        $this->actingAs($user)->getJson('/live-shopping/stores')->assertStatus(200);
    }

    public function test_stores_route_reports_an_unreachable_engine_as_unavailable_and_caches_nothing(): void
    {
        $user = User::factory()->createQuietly();
        Http::fake(['engine.test/v1/catalog' => Http::response('nope', 500)]);

        $this->actingAs($user)->getJson('/live-shopping/stores')
            ->assertStatus(503)
            ->assertJson(['success' => false, 'code' => 'engine_unavailable']);
        $this->assertNull(Cache::get(LiveShoppingEngine::CATALOG_CACHE_KEY));
    }

    public function test_stores_route_rejects_a_malformed_catalog_entry(): void
    {
        $user = User::factory()->createQuietly();
        Http::fake(['engine.test/v1/catalog' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1,
            'stores'         => [['id' => 'Bad Store!', 'name' => 'x', 'url' => 'https://x.example/']],
        ]], 200)]);

        $this->actingAs($user)->getJson('/live-shopping/stores')->assertStatus(503);
    }

    public function test_unknown_store_at_create_is_an_actionable_422_and_the_row_says_so(): void
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);
        Http::fake(['engine.test/*' => Http::response(['ok' => false, 'error' => ['code' => 'unknown_store']], 404)]);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id,
            'objective'       => 'running shoes',
            'store_id'        => 'walmart',
        ])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'store_unsupported', 'message' => 'that store is not available for live shopping yet']);

        $session = LiveShoppingSession::first();
        $this->assertSame('failed', $session->status);
        $this->assertSame('store_unsupported', $session->error_code);
        $this->assertNull($session->active_slot);
    }

    public function test_other_engine_refusals_stay_opaque(): void
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);
        Http::fake(['engine.test/*' => Http::response(['ok' => false, 'error' => ['code' => 'something_internal']], 400)]);

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id,
            'objective'       => 'running shoes',
            'store_id'        => 'target',
        ])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'engine_refused']);

        $this->assertSame('engine_unavailable', LiveShoppingSession::first()->error_code);
    }

    /** L2 (multi-store): the engine's advertised cap passes through; anything outside 1..4 reads as 1. */
    public function test_stores_route_passes_the_engine_store_cap_through(): void
    {
        $user = User::factory()->createQuietly();
        // One fake with a sequence: a second Http::fake() would stack behind the first stub, not replace it.
        $stores = [['id' => 'target', 'name' => 'Target', 'url' => 'https://www.target.com/']];
        Http::fake(['engine.test/v1/catalog' => Http::sequence()
            ->push(['ok' => true, 'data' => ['schema_version' => 1, 'max_stores_per_session' => 2, 'stores' => $stores]], 200)
            ->push(['ok' => true, 'data' => ['schema_version' => 1, 'max_stores_per_session' => 9, 'stores' => $stores]], 200)]);
        $this->actingAs($user)->getJson('/live-shopping/stores')->assertStatus(200)->assertJsonPath('max_stores_per_session', 2);

        \Illuminate\Support\Facades\Cache::flush();
        // An out-of-range cap (9) is bounded to the one-store default, and the cap is not memoised across requests.
        $this->actingAs($user)->getJson('/live-shopping/stores')->assertStatus(200)->assertJsonPath('max_stores_per_session', 1);
    }
    /** Remote store browser: the storefront URL feeds the store cards; only
     *  https URLs pass, anything else is dropped without failing the catalog. */
    public function test_stores_route_forwards_https_storefront_urls_only(): void
    {
        $user = User::factory()->createQuietly();
        Http::fake(['engine.test/v1/catalog' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1,
            'stores'         => [
                ['id' => 'target', 'name' => 'Target', 'url' => 'https://www.target.com/'],
                ['id' => 'plain', 'name' => 'Plain', 'url' => 'http://plain.example/'],
                ['id' => 'creds', 'name' => 'Creds', 'url' => 'https://u:p@creds.example/'],
            ],
        ]], 200)]);

        $this->actingAs($user)->getJson('/live-shopping/stores')
            ->assertOk()
            ->assertJsonPath('stores.0.url', 'https://www.target.com/')
            ->assertJsonMissingPath('stores.1.url')
            ->assertJsonMissingPath('stores.2.url');
    }
}
