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
                ['id' => 'target', 'name' => 'Target'],
                ['id' => 'walmart', 'name' => 'Walmart'],
                ['id' => 'acme-outlet', 'name' => 'Acme Outlet'],
            ]]);

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
}
