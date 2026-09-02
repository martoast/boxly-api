<?php

namespace Tests\Feature\LiveShopping;

use App\Jobs\ProcessLiveShoppingResultJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\LiveShoppingSession;
use App\Models\LiveShoppingWebhookReceipt;
use App\Models\SearchEvent;
use App\Models\User;
use Tests\LiveShoppingTestCase;
use Tests\LiveShopping\Concerns\SignsDeliveries;

class ResultJobTest extends LiveShoppingTestCase
{
    use SignsDeliveries;

    private User $user;

    private Conversation $conversation;

    private LiveShoppingSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->createQuietly();
        $this->conversation = Conversation::create(['user_id' => $this->user->id]);
        $this->session = LiveShoppingSession::create([
            'user_id' => $this->user->id, 'conversation_id' => $this->conversation->id,
            'engine_session_id' => 'eng_1', 'status' => 'running', 'store_id' => 'on',
            'stores' => [], 'objective' => 'check stock', 'latest_seq' => 5, 'active_slot' => 1,
        ]);
    }

    private function receipt(array $overrides = []): LiveShoppingWebhookReceipt
    {
        $payload = $this->storedPayload(array_merge(
            ['conversation_id' => (string) $this->conversation->id],
            $overrides,
        ));

        return LiveShoppingWebhookReceipt::create([
            'delivery_id'    => $payload['delivery_id'],
            'content_sha256' => hash('sha256', json_encode($payload)),
            'payload'        => $payload,
            'status'         => 'received',
            'received_at'    => now(),
        ]);
    }

    public function test_it_appends_the_part_completes_the_session_and_releases_the_slot(): void
    {
        $receipt = $this->receipt();

        (new ProcessLiveShoppingResultJob($receipt->id))->handle();

        $session = $this->session->fresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame('dlv_1', $session->terminal_delivery_id);
        $this->assertSame(10, $session->terminal_seq);
        $this->assertNull($session->active_slot);
        $this->assertSame('processed', $receipt->fresh()->status);

        $message = ConversationMessage::first();
        $this->assertSame('assistant', $message->role);
        // The `parts` wrapper is what deriveProducts reads.
        $this->assertSame('tool-live_results', $message->content['parts'][0]['type']);
        $this->assertNotNull($this->conversation->fresh()->last_message_at);
    }

    /** The rail must actually surface it — this is the customer-visible check. */
    public function test_the_result_surfaces_through_the_conversation_endpoint(): void
    {
        (new ProcessLiveShoppingResultJob($this->receipt()->id))->handle();

        $response = $this->actingAs($this->user)
            ->getJson("/conversations/{$this->conversation->id}");

        $response->assertOk()
            ->assertJsonPath('data.products.0.title', 'Cloudmonster 2')
            ->assertJsonPath('data.products.0.url', 'https://www.on.com/cloudmonster-2');
    }

    public function test_it_writes_exactly_one_source_segmented_search_event(): void
    {
        (new ProcessLiveShoppingResultJob($this->receipt()->id))->handle();

        $this->assertSame(1, SearchEvent::count());
        $event = SearchEvent::first();
        $this->assertSame('live_engine', $event->source);
        $this->assertSame('search', $event->type);
        $this->assertSame(1, $event->results);
    }

    /**
     * A delivery for a session we cannot see yet is NOT an orphan. A fast engine
     * can deliver terminal after durable acceptance but before the create
     * request has stored engine_session_id, so a young receipt stays retryable.
     */
    public function test_an_unknown_session_stays_retryable_inside_the_horizon(): void
    {
        $receipt = $this->receipt(['session_id' => 'eng_not_saved_yet']);

        (new ProcessLiveShoppingResultJob($receipt->id))->handle();

        $receipt->refresh();
        $this->assertSame('received', $receipt->status);   // the drainer will retry
        $this->assertSame(1, $receipt->attempts);
        $this->assertSame(0, ConversationMessage::count());
    }

    /** The webhook-before-create race, resolved: eventually exactly-once. */
    public function test_a_delivery_that_arrived_before_the_session_was_saved_is_applied_on_retry(): void
    {
        $receipt = $this->receipt(['session_id' => 'eng_late']);

        (new ProcessLiveShoppingResultJob($receipt->id))->handle();
        $this->assertSame('received', $receipt->fresh()->status);
        $this->assertSame(0, ConversationMessage::count());

        // The create call finally lands and stores the engine id.
        $this->session->forceFill(['engine_session_id' => 'eng_late'])->save();

        (new ProcessLiveShoppingResultJob($receipt->id))->handle();
        (new ProcessLiveShoppingResultJob($receipt->id))->handle();   // and a duplicate

        $this->assertSame('processed', $receipt->fresh()->status);
        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame('completed', $this->session->fresh()->status);
    }

    /** Past the horizon it really is orphaned, and says so rather than lying. */
    public function test_an_unknown_session_past_the_horizon_is_marked_orphaned(): void
    {
        $receipt = $this->receipt(['session_id' => 'eng_never_existed']);
        $receipt->forceFill(['received_at' => now()->subHour()])->save();

        (new ProcessLiveShoppingResultJob($receipt->id))->handle();

        $receipt->refresh();
        $this->assertSame('failed', $receipt->status);
        $this->assertSame('orphaned', $receipt->error_code);
    }

    /** Terminal states are absorbing: first delivery wins, always. */
    public function test_a_later_delivery_cannot_overwrite_a_terminal_status(): void
    {
        (new ProcessLiveShoppingResultJob($this->receipt()->id))->handle();

        $second = $this->receipt([
            'delivery_id' => 'dlv_2', 'terminal_seq' => 11,
            'result' => ['outcome' => 'failed', 'products' => [], 'error_code' => 'boom'],
        ]);

        (new ProcessLiveShoppingResultJob($second->id))->handle();

        $this->assertSame('completed', $this->session->fresh()->status);
        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame(1, SearchEvent::count());
        // A DIFFERENT delivery contradicting the recorded outcome is a genuine
        // conflict, not a quiet duplicate.
        $this->assertSame('conflict', $second->fresh()->status);
        $this->assertSame('already_terminal', $second->fresh()->error_code);
    }

    /** Re-delivery of the SAME terminal event stays an idempotent replay. */
    public function test_the_same_terminal_delivery_replayed_stays_processed(): void
    {
        $receipt = $this->receipt();
        (new ProcessLiveShoppingResultJob($receipt->id))->handle();

        $receipt->forceFill(['status' => 'received', 'processed_at' => null])->save();
        (new ProcessLiveShoppingResultJob($receipt->id))->handle();

        $this->assertSame('processed', $receipt->fresh()->status);
        $this->assertSame(1, ConversationMessage::count());
    }

    public function test_a_failed_outcome_records_the_error_code(): void
    {
        $receipt = $this->receipt([
            'result' => ['outcome' => 'failed', 'products' => [], 'error_code' => 'store_unreachable'],
        ]);

        (new ProcessLiveShoppingResultJob($receipt->id))->handle();

        $session = $this->session->fresh();
        $this->assertSame('failed', $session->status);
        $this->assertSame('store_unreachable', $session->error_code);
        $this->assertNull($session->active_slot);
    }

    /**
     * The FK is nullOnDelete, so a conversation can vanish mid-session. That is
     * a deleted thread, not a correlation failure: skip the append, still
     * complete the terminal transition and release the slot.
     */
    public function test_a_deleted_conversation_skips_the_append_but_still_completes(): void
    {
        $receipt = $this->receipt();
        $this->conversation->delete();

        (new ProcessLiveShoppingResultJob($receipt->id))->handle();

        $session = $this->session->fresh();
        $this->assertSame('completed', $session->status);
        $this->assertNull($session->active_slot);
        $this->assertSame(0, ConversationMessage::count());
        $this->assertSame(1, SearchEvent::count());
    }

    public function test_a_correlation_mismatch_appends_nothing(): void
    {
        $receipt = $this->receipt(['conversation_id' => '999999']);

        (new ProcessLiveShoppingResultJob($receipt->id))->handle();

        $this->assertSame('conflict', $receipt->fresh()->status);
        $this->assertSame(0, ConversationMessage::count());
        $this->assertSame('running', $this->session->fresh()->status);
    }

    public function test_a_stale_terminal_seq_appends_nothing(): void
    {
        $receipt = $this->receipt(['terminal_seq' => 1]);   // below latest_seq = 5

        (new ProcessLiveShoppingResultJob($receipt->id))->handle();

        $this->assertSame('conflict', $receipt->fresh()->status);
        $this->assertSame(0, ConversationMessage::count());
        $this->assertSame('running', $this->session->fresh()->status);
    }

    /**
     * A re-parented or corrupted row must never let one customer's result land
     * in another customer's conversation.
     */
    public function test_it_does_not_append_across_owners(): void
    {
        $receipt = $this->receipt();
        $other = User::factory()->createQuietly();
        $this->conversation->forceFill(['user_id' => $other->id])->save();

        (new ProcessLiveShoppingResultJob($receipt->id))->handle();

        $this->assertSame(0, ConversationMessage::count());
        // The terminal transition still completes — the slot must not leak.
        $session = $this->session->fresh();
        $this->assertSame('completed', $session->status);
        $this->assertNull($session->active_slot);
    }

    /** Duplicate dispatch: the receipt's status transition is the arbiter. */
    public function test_running_the_same_receipt_twice_has_effect_exactly_once(): void
    {
        $receipt = $this->receipt();

        (new ProcessLiveShoppingResultJob($receipt->id))->handle();
        (new ProcessLiveShoppingResultJob($receipt->id))->handle();

        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame(1, SearchEvent::count());
    }

    /** No path may leave a receipt at 'received' — the drainer would loop on it. */
    public function test_every_path_leaves_the_receipt_in_a_terminal_state(): void
    {
        $cases = [
            ['conversation_id' => '999999'],
            ['terminal_seq' => 1],
        ];

        foreach ($cases as $i => $patch) {
            $receipt = $this->receipt($patch + ['delivery_id' => 'dlv_case_' . $i]);

            (new ProcessLiveShoppingResultJob($receipt->id))->handle();

            $this->assertNotSame('received', $receipt->fresh()->status);
        }
    }

    /**
     * latest_seq is the sequence ALREADY observed at accept time, so an equal
     * terminal_seq is stale, not fresh.
     */
    public function test_a_terminal_seq_equal_to_latest_seq_is_stale(): void
    {
        $receipt = $this->receipt(['terminal_seq' => 5]);   // latest_seq is 5

        (new ProcessLiveShoppingResultJob($receipt->id))->handle();

        $this->assertSame('conflict', $receipt->fresh()->status);
        $this->assertSame('stale_seq', $receipt->fresh()->error_code);
        $this->assertSame('running', $this->session->fresh()->status);
    }

    /** The persisted part is EXACTLY the frozen contract shape — nothing added. */
    public function test_the_persisted_part_has_no_invented_fields(): void
    {
        (new ProcessLiveShoppingResultJob($this->receipt()->id))->handle();

        $part = ConversationMessage::first()->content['parts'][0];

        $this->assertSame(['type', 'state', 'output'], array_keys($part));
        $this->assertSame('tool-live_results', $part['type']);
        $this->assertArrayNotHasKey('toolCallId', $part);
        $this->assertArrayNotHasKey('input', $part);
    }
}
