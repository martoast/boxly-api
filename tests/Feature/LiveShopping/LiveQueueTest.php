<?php

namespace Tests\Feature\LiveShopping;

use App\Jobs\DrainLiveQueueJob;
use App\Models\Conversation;
use App\Models\LiveShoppingSession;
use App\Models\User;
use App\Services\LiveQueue;
use App\Services\LiveShoppingEngine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\LiveShoppingTestCase;

/**
 * Simultaneous shoppers (2026-09-28): a live search that finds the engine full waits in line (LiveQueue) instead of
 * failing, shows its place, and starts — in order — the moment a slot frees.
 */
class LiveQueueTest extends LiveShoppingTestCase
{
    private function busy(): void
    {
        Http::fake(['engine.test/*' => Http::response(['ok' => false, 'error' => ['code' => 'engine_busy', 'message' => 'busy', 'retryable' => true]], 503)]);
    }

    private function search(User $user): \Illuminate\Testing\TestResponse
    {
        $conversation = Conversation::create(['user_id' => $user->id, 'title' => 't']);

        return $this->actingAs($user)->postJson('/live-shopping/sessions', ['conversation_id' => $conversation->id, 'objective' => 'leggings', 'store_id' => 'on']);
    }

    public function test_a_full_engine_puts_the_search_in_line_instead_of_failing(): void
    {
        Queue::fake();
        $this->busy();
        $r = $this->search(User::factory()->createQuietly())->assertStatus(201);
        $this->assertTrue($r->json('data.queued'));
        $this->assertSame(1, $r->json('data.queue_position'));
        $row = LiveShoppingSession::first();
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->engine_session_id);
        $this->assertSame(1, $row->active_slot, 'the shopper keeps their one active slot while waiting');
        $this->assertNotNull($row->expires_at, 'a deadline, so the reaper leaves it alone');
        Queue::assertPushed(DrainLiveQueueJob::class);
    }

    public function test_places_in_line_follow_arrival(): void
    {
        Queue::fake();
        $this->busy();
        $this->search(User::factory()->createQuietly());
        $this->travel(1)->seconds();
        $second = $this->search(User::factory()->createQuietly())->assertStatus(201);
        $this->assertSame(2, $second->json('data.queue_position'));
    }

    public function test_the_drain_starts_waiting_shoppers_in_order_when_a_slot_frees(): void
    {
        Queue::fake();
        $u1 = User::factory()->createQuietly();
        $u2 = User::factory()->createQuietly();
        $c1 = Conversation::create(['user_id' => $u1->id, 'title' => 't']);
        $c2 = Conversation::create(['user_id' => $u2->id, 'title' => 't']);
        $busy = ['ok' => false, 'error' => ['code' => 'engine_busy', 'message' => 'busy', 'retryable' => true]];
        $started = fn (string $id, int $conv) => ['ok' => true, 'data' => ['schema_version' => 1, 'session' => [
            'id' => $id, 'conversation_id' => (string) $conv, 'store_id' => 'on', 'status' => 'running', 'latest_seq' => 5,
            'created_at' => now()->toIso8601String(), 'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ]]];
        // Both arrive while the engine is full, then a slot frees for each in turn.
        Http::fake(['engine.test/*' => Http::sequence()->push($busy, 503)->push($busy, 503)->push($started('eng_q1', $c1->id), 201)->push($started('eng_q2', $c2->id), 201)]);
        $this->actingAs($u1)->postJson('/live-shopping/sessions', ['conversation_id' => $c1->id, 'objective' => 'leggings', 'store_id' => 'on'])->assertStatus(201);
        $this->travel(1)->seconds();
        $this->actingAs($u2)->postJson('/live-shopping/sessions', ['conversation_id' => $c2->id, 'objective' => 'joggers', 'store_id' => 'on'])->assertStatus(201);

        $this->assertFalse(LiveQueue::drain(app(LiveShoppingEngine::class)), 'nobody left waiting');
        $rows = LiveShoppingSession::orderBy('id')->get();
        $this->assertSame(['eng_q1', 'eng_q2'], $rows->pluck('engine_session_id')->all(), 'first in line starts first');
        foreach ($rows as $row) {
            $this->assertSame('running', $row->status);
            $this->assertNull($row->queued_at);
        }
    }

    public function test_a_busy_drain_keeps_them_waiting_and_runs_again(): void
    {
        Queue::fake();
        $this->busy();
        $this->search(User::factory()->createQuietly());
        $this->assertTrue(LiveQueue::drain(app(LiveShoppingEngine::class)), 'still someone waiting: the job runs again');
        (new DrainLiveQueueJob())->handle(app(LiveShoppingEngine::class));
        $this->assertTrue(LiveQueue::isQueued(LiveShoppingSession::first()));
    }

    public function test_a_wait_past_its_deadline_fails_and_frees_the_slot(): void
    {
        Queue::fake();
        $this->busy();
        $this->search(User::factory()->createQuietly());
        $this->travel(LiveQueue::TTL_SECONDS + 5)->seconds();
        LiveQueue::drain(app(LiveShoppingEngine::class));
        $row = LiveShoppingSession::first();
        $this->assertSame('failed', $row->status);
        $this->assertSame('queue_timeout', $row->error_code);
        $this->assertNull($row->active_slot);
    }
}
