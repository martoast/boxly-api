<?php

namespace Tests\Feature\LiveShopping;

use App\Jobs\ProcessLiveShoppingResultJob;
use App\Models\Conversation;
use App\Models\LiveShoppingSession;
use App\Models\LiveShoppingWebhookReceipt;
use App\Models\SearchEvent;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\LiveShoppingTestCase;
use Tests\LiveShopping\Concerns\SignsDeliveries;

/**
 * `main` auto-deploys production, so "it fails closed" and "it rolls back" are
 * claims that need evidence rather than assertion.
 */
class ProductionSafetyTest extends LiveShoppingTestCase
{
    use SignsDeliveries;

    private function actor(): array
    {
        $user = User::factory()->createQuietly();

        return [$user, Conversation::create(['user_id' => $user->id])];
    }

    /**
     * Disabling is the rollback: one env var, no redeploy, no data touched, no
     * migration to reverse. Nothing may be written or queued.
     */
    public function test_disabled_writes_nothing_and_queues_nothing(): void
    {
        Queue::fake();
        Http::fake();
        $this->configureEngine(['enabled' => false]);
        [$user, $conversation] = $this->actor();

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(503);

        $this->deliver($this->body())->assertStatus(404);

        $this->assertSame(0, LiveShoppingSession::count());
        $this->assertSame(0, LiveShoppingWebhookReceipt::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    /** A half-configured deployment behaves exactly like an unconfigured one. */
    public function test_a_missing_base_url_or_secret_disables_the_feature(): void
    {
        [$user, $conversation] = $this->actor();

        foreach ([['base_url' => null], ['service_secret' => null]] as $gap) {
            $this->configureEngine($gap);

            $this->actingAs($user)->postJson('/live-shopping/sessions', [
                'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
            ])->assertStatus(503);
        }

        $this->assertSame(0, LiveShoppingSession::count());
    }

    /** Unknown key id = closed. An unset webhook secret must never open the door. */
    public function test_the_webhook_is_closed_without_a_configured_key(): void
    {
        $this->configureEngine(['webhook_keys' => null]);

        $this->deliver($this->body())->assertStatus(403);
        $this->assertSame(0, LiveShoppingWebhookReceipt::count());
    }

    public function test_show_and_ticket_are_also_honest_503_when_disabled(): void
    {
        $this->configureEngine(['enabled' => false]);
        [$user, $conversation] = $this->actor();
        $session = LiveShoppingSession::create([
            'user_id' => $user->id, 'conversation_id' => $conversation->id,
            'engine_session_id' => 'eng_1', 'status' => 'running', 'store_id' => 'on',
            'stores' => [], 'objective' => 'x', 'active_slot' => 1,
        ]);

        $this->actingAs($user)->getJson("/live-shopping/sessions/{$session->id}")->assertStatus(503);
        $this->actingAs($user)->postJson("/live-shopping/sessions/{$session->id}/ticket")->assertStatus(503);
    }

    /**
     * CLAUDE.md's deploy-window rule: a read path querying a brand-new table
     * must not 500 while the migration catches up. This repo has been bitten by
     * exactly this before.
     */
    public function test_read_paths_are_honest_503_before_the_migration_runs(): void
    {
        [$user, $conversation] = $this->actor();

        // Child first. On MySQL, dropping live_shopping_sessions while the
        // receipts FK still points at it is error 3730 — and both tables arrive
        // in the same migration batch anyway, so "before the migration runs"
        // means neither exists, not one without the other.
        Schema::dropIfExists('live_shopping_webhook_receipts');
        Schema::drop('live_shopping_sessions');

        $this->actingAs($user)->postJson('/live-shopping/sessions', [
            'conversation_id' => $conversation->id, 'objective' => 'x', 'store_id' => 'on',
        ])->assertStatus(503);
    }

    public function test_the_webhook_404s_before_its_table_exists(): void
    {
        Schema::drop('live_shopping_webhook_receipts');

        $this->deliver($this->body())->assertStatus(404);
    }

    public function test_the_result_job_is_inert_before_its_table_exists(): void
    {
        Schema::drop('live_shopping_webhook_receipts');

        (new ProcessLiveShoppingResultJob(1))->handle();

        $this->assertTrue(true);   // no exception: the drainer retries after migrate
    }

    /**
     * THE regression that matters most: the admin AI-search series must not
     * shift the day live sessions start running. A moved dashboard is the kind
     * of breakage nobody notices for a week.
     */
    public function test_live_engine_events_do_not_move_the_admin_metrics(): void
    {
        $admin = User::factory()->createQuietly(['role' => 'admin']);

        foreach ([['type' => 'search', 'query' => 'shoes', 'results' => 10],
                  ['type' => 'search', 'query' => 'socks', 'results' => 0],
                  ['type' => 'product_view', 'title' => 'A'],
                  ['type' => 'question', 'query' => 'how long?']] as $row) {
            SearchEvent::create($row + ['user_id' => $admin->id]);
        }

        $before = [
            'stats'   => $this->actingAs($admin)->getJson('/admin/ai-search/stats')->json('data'),
            'queries' => $this->actingAs($admin)->getJson('/admin/ai-search/queries')->json('data'),
        ];

        // Now the live engine starts writing.
        for ($i = 0; $i < 5; $i++) {
            SearchEvent::create([
                'user_id' => $admin->id, 'type' => 'search', 'source' => 'live_engine',
                'query' => 'live objective ' . $i, 'results' => 3,
            ]);
        }

        $after = [
            'stats'   => $this->actingAs($admin)->getJson('/admin/ai-search/stats')->json('data'),
            'queries' => $this->actingAs($admin)->getJson('/admin/ai-search/queries')->json('data'),
        ];

        $this->assertSame($before['stats'], $after['stats'], 'live_engine rows shifted the stats series');
        $this->assertSame($before['queries'], $after['queries'], 'live objectives leaked into the intent map');
    }

    /** The organic corpus keeps writing NULL: SearchEventController is untouched. */
    public function test_the_public_logging_endpoint_still_writes_a_null_source(): void
    {
        $this->postJson('/search-events', ['type' => 'search', 'query' => 'shoes'])->assertOk();

        $this->assertNull(SearchEvent::first()->source);
    }
}
