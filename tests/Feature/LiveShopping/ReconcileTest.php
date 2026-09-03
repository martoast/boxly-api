<?php

namespace Tests\Feature\LiveShopping;

use App\Models\Conversation;
use App\Models\LiveShoppingSession;
use App\Models\LiveShoppingWebhookReceipt;
use App\Models\User;
use Tests\LiveShoppingTestCase;

/**
 * The reconciler exists so a stuck session cannot lock a customer out of the
 * feature forever: active_slot is claimed at INSERT and released only by a
 * terminal transition, and P1 has no cancel route.
 *
 * The deadline is always the ENGINE's. A blind created_at timeout would kill
 * sessions the engine still considers alive.
 */
class ReconcileTest extends LiveShoppingTestCase
{
    private function liveSession(array $overrides = []): LiveShoppingSession
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);

        return LiveShoppingSession::create(array_merge([
            'user_id' => $user->id, 'conversation_id' => $conversation->id,
            'engine_session_id' => 'eng_' . uniqid(), 'status' => 'running',
            'store_id' => 'on', 'stores' => [], 'objective' => 'x', 'active_slot' => 1,
        ], $overrides));
    }

    public function test_a_session_past_the_engine_deadline_is_failed_and_its_slot_released(): void
    {
        $session = $this->liveSession(['expires_at' => now()->subMinutes(10)]);

        $this->artisan('boxly:live-shopping-reconcile')->assertSuccessful();

        $session = $session->fresh();
        $this->assertSame('failed', $session->status);
        $this->assertSame('expired', $session->error_code);
        $this->assertNull($session->active_slot);
    }

    /** No blind age timeout: a live deadline is respected however old the row is. */
    public function test_a_session_still_within_its_deadline_is_left_alone(): void
    {
        $session = $this->liveSession([
            'expires_at' => now()->addMinutes(10),
            'created_at' => now()->subDays(1),
        ]);

        $this->artisan('boxly:live-shopping-reconcile')->assertSuccessful();

        $this->assertSame('running', $session->fresh()->status);
        $this->assertSame(1, (int) $session->fresh()->active_slot);
    }

    /**
     * The crash-before-engine-response case: INSERT committed, the create call
     * never returned, so no deadline was ever recorded. Bounded by the call
     * timeout, not by an age rule on a live session.
     */
    public function test_a_crashed_create_with_no_deadline_is_released(): void
    {
        $session = $this->liveSession([
            'status' => 'pending', 'expires_at' => null, 'engine_session_id' => null,
        ]);
        $session->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $this->artisan('boxly:live-shopping-reconcile')->assertSuccessful();

        $this->assertNull($session->fresh()->active_slot);
    }

    public function test_a_fresh_pending_row_is_not_swept(): void
    {
        $session = $this->liveSession(['status' => 'pending', 'expires_at' => null]);

        $this->artisan('boxly:live-shopping-reconcile')->assertSuccessful();

        $this->assertSame('pending', $session->fresh()->status);
    }

    public function test_after_expiry_the_user_can_start_another_session(): void
    {
        $session = $this->liveSession(['expires_at' => now()->subMinutes(10)]);
        $this->artisan('boxly:live-shopping-reconcile')->assertSuccessful();

        // The slot is genuinely free: the unique index accepts a new claim.
        LiveShoppingSession::create([
            'user_id' => $session->user_id, 'conversation_id' => $session->conversation_id,
            'status' => 'pending', 'store_id' => 'on', 'stores' => [], 'objective' => 'y',
            'active_slot' => 1,
        ]);

        $this->assertSame(1, LiveShoppingSession::whereNotNull('active_slot')->count());
    }

    /**
     * Reaper and webhook race, both orders. Either way exactly one terminal
     * status and one slot release — neither can double-apply.
     */
    public function test_a_late_terminal_delivery_after_expiry_does_not_flip_the_status_back(): void
    {
        $session = $this->liveSession([
            'engine_session_id' => 'eng_race', 'expires_at' => now()->subMinutes(10),
        ]);
        $this->artisan('boxly:live-shopping-reconcile')->assertSuccessful();
        $this->assertSame('failed', $session->fresh()->status);

        $receipt = LiveShoppingWebhookReceipt::create([
            'delivery_id' => 'dlv_late', 'content_sha256' => str_repeat('a', 64),
            'payload' => [
                'delivery_id' => 'dlv_late', 'session_id' => 'eng_race',
                'conversation_id' => (string) $session->conversation_id,
                'terminal_seq' => 9,
                'result' => ['outcome' => 'completed', 'error_code' => null],
                'assistant_part' => [
                    'type' => 'tool-live_results', 'state' => 'output-available',
                    'output' => ['products' => []],
                ],
            ],
            'status' => 'received', 'received_at' => now(),
        ]);

        (new \App\Jobs\ProcessLiveShoppingResultJob($receipt->id))->handle();

        // Terminal is absorbing: 'failed' stands, the receipt is kept.
        $this->assertSame('failed', $session->fresh()->status);
        $this->assertSame('expired', $session->fresh()->error_code);
        // A different delivery contradicting the recorded terminal outcome is a
        // conflict, and is recorded as one rather than quietly "processed".
        $this->assertSame('conflict', $receipt->fresh()->status);
        $this->assertSame(0, \App\Models\ConversationMessage::count());
    }

    public function test_the_reaper_is_a_no_op_when_the_feature_is_disabled(): void
    {
        $this->configureEngine(['enabled' => false]);
        $session = $this->liveSession(['expires_at' => now()->subMinutes(10)]);

        $this->artisan('boxly:live-shopping-reconcile')->assertSuccessful();

        $this->assertSame('running', $session->fresh()->status);
    }
}
