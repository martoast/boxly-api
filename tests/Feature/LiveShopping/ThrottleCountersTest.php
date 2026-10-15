<?php

namespace Tests\Feature\LiveShopping;

use App\Models\User;
use App\Services\LiveShoppingEngine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\LiveShoppingTestCase;

/**
 * EACH LIMIT HAS ITS OWN COUNTER (prod 2026-10-14). Unnamed, Laravel keys every `throttle:N,1` on the user alone, so the
 * chat's status polls and video tickets during one live search used up "create"'s 10 a minute: a shopper who started a
 * new search 26 s after watching another was refused 429 before the request reached the engine. Reproduced on prod: 12
 * reads of /live-shopping/stores, then a create, answered 429.
 */
class ThrottleCountersTest extends LiveShoppingTestCase
{
    public function test_reading_the_store_list_does_not_use_up_the_searches_a_shopper_may_start(): void
    {
        Cache::forget(LiveShoppingEngine::CATALOG_CACHE_KEY);
        Http::fake(['engine.test/v1/catalog' => Http::response(['ok' => true, 'data' => [
            'schema_version' => 1,
            'stores'         => [['id' => 'target', 'name' => 'Target', 'url' => 'https://www.target.com/']],
        ]], 200)]);
        $user = User::factory()->createQuietly();

        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($user)->getJson('/live-shopping/stores')->assertStatus(200);
        }

        // An empty create is refused for its body (422), never for a limit the store list used up (429).
        $this->actingAs($user)->postJson('/live-shopping/sessions', [])->assertStatus(422);
    }
}
