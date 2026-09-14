<?php

namespace Tests\Feature;

use App\Models\SearchEvent;
use App\Models\User;
use Tests\LiveShoppingTestCase;

/**
 * /search-events is the ONE way a search reaches analytics.
 *
 * It used to be two ways: a question was reported here by the app, while a search was
 * written inside /products/search, which saw the served rows first-hand. When the AI
 * moved to reading the catalog directly (boxly f436374, 2026-09-03) that second write
 * stopped firing — questions kept flowing and searches went dark for ten days, with the
 * admin dashboard reporting 0 searches while customers were plainly searching.
 *
 * So a search is now reported like a question is, and it has to be able to carry
 * everything the old write did: how many rows were served, which rows, and whether the
 * catalog BROADENED the ask (generic store fill, not matches) — that last one is what
 * stops filler from reading as a hit.
 *
 *   vendor/bin/phpunit tests/Feature/SearchEventTest.php
 */
class SearchEventTest extends LiveShoppingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', [
            '--path'  => 'database/migrations/2026_06_21_000001_create_search_events_table.php',
            '--force' => true,
        ]);
        foreach ([
            'database/migrations/2026_06_21_000002_add_results_sample_to_search_events.php',
            'database/migrations/2026_06_22_000002_widen_search_events_query.php',
            'database/migrations/2026_07_07_000000_add_conversation_id_to_search_events_table.php',
            'database/migrations/2026_08_11_000000_add_broadened_to_search_events_table.php',
            'database/migrations/2026_09_01_010000_add_source_to_search_events_table.php',
        ] as $m) {
            $this->artisan('migrate', ['--path' => $m, '--force' => true]);
        }
    }

    public function test_a_search_is_recorded_with_its_rows_and_broadened_flag(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/search-events', [
            'type'            => 'search',
            'query'           => 'tenis Adidas promociones',
            'results'         => 12,
            'broadened'       => true,
            'served_query'    => 'Adidas',
            'conversation_id' => null,
            'results_sample'  => [
                ['store' => 'adidas', 'title' => 'Samba OG Shoes', 'price' => 100],
                ['store' => 'Nike', 'title' => 'Pegasus 41', 'price' => 130.5],
            ],
        ])->assertOk();

        $e = SearchEvent::where('type', 'search')->firstOrFail();
        $this->assertSame('tenis Adidas promociones', $e->query);
        $this->assertSame(12, $e->results);
        $this->assertSame($user->id, $e->user_id);
        $this->assertTrue($e->broadened, 'a broadened search must not read as a real hit');
        $this->assertSame('Adidas', $e->served_query);
        $this->assertCount(2, $e->results_sample);
        $this->assertSame('adidas', $e->results_sample[0]['store']);
        $this->assertSame(100, $e->results_sample[0]['price']);
    }

    public function test_a_search_that_served_nothing_is_still_recorded(): void
    {
        // A zero-result search is the single most useful row in the table — it is demand
        // we failed. It must never be dropped for having no rows to sample.
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/search-events', [
            'type'    => 'search',
            'query'   => 'Psycho Bunny t-shirt',
            'results' => 0,
        ])->assertOk();

        $e = SearchEvent::where('type', 'search')->firstOrFail();
        $this->assertSame(0, $e->results);
        $this->assertFalse((bool) $e->broadened);
        $this->assertNull($e->results_sample);
    }

    public function test_a_question_still_keeps_its_answer(): void
    {
        // The path questions have always used must not regress while searches join it.
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/search-events', [
            'type'   => 'question',
            'query'  => '¿Cuánto cuesta la caja mediana?',
            'answer' => 'La caja Mediana cuesta $4,400 MXN.',
        ])->assertOk();

        $e = SearchEvent::where('type', 'question')->firstOrFail();
        $this->assertStringContainsString('4,400', $e->results_sample[0]['answer']);
    }

    public function test_analytics_never_breaks_the_caller(): void
    {
        // Nothing here may surface an error to a shopper mid-turn.
        $this->postJson('/search-events', ['type' => 'not-a-type'])->assertOk();
    }
}
