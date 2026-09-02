<?php

namespace Tests\Feature\LiveShopping;

use App\Jobs\ProcessLiveShoppingResultJob;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\LiveShoppingSession;
use App\Models\LiveShoppingWebhookReceipt;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\LiveShoppingTestCase;

/**
 * The drainer is what makes the webhook's 202 a promise rather than a hope.
 *
 * The fast-path dispatch is only a latency optimisation: if the process died
 * between committing the receipt and dispatching, or the queue was down, the
 * work would be lost even though we told the engine it would happen. With this
 * sweep, the only thing that must succeed is the commit the response waited on.
 */
class InboxDrainerTest extends LiveShoppingTestCase
{
    private function receipt(array $overrides = []): LiveShoppingWebhookReceipt
    {
        return LiveShoppingWebhookReceipt::create(array_merge([
            'delivery_id'    => 'dlv_' . uniqid(),
            'content_sha256' => str_repeat('a', 64),
            'payload'        => [
                'delivery_id' => 'dlv_x', 'session_id' => 'eng_x',
                'conversation_id' => '1', 'terminal_seq' => 1,
                'result' => ['outcome' => 'completed', 'error_code' => null],
                'assistant_part' => [
                    'type' => 'tool-live_results', 'state' => 'output-available',
                    'output' => ['products' => []],
                ],
            ],
            'status'         => 'received',
            'received_at'    => now()->subMinutes(5),
        ], $overrides));
    }

    public function test_it_dispatches_receipts_past_the_grace(): void
    {
        Queue::fake();
        $this->receipt();

        $this->artisan('boxly:live-shopping-drain')->assertSuccessful();

        Queue::assertPushed(ProcessLiveShoppingResultJob::class, 1);
    }

    /** The grace keeps it from racing a job already in flight on the fast path. */
    public function test_it_leaves_receipts_inside_the_grace_alone(): void
    {
        Queue::fake();
        $this->receipt(['received_at' => now()]);

        $this->artisan('boxly:live-shopping-drain')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_it_never_re_dispatches_a_processed_receipt(): void
    {
        Queue::fake();
        $this->receipt(['status' => 'processed', 'processed_at' => now()]);
        $this->receipt(['status' => 'conflict']);

        $this->artisan('boxly:live-shopping-drain')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /**
     * The crash/retry case. A receipt was committed and the engine was told
     * "accepted", but the job never ran. The work must still happen — exactly
     * once — with no message duplicated.
     */
    public function test_a_receipt_whose_job_never_ran_is_still_processed_exactly_once(): void
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);
        LiveShoppingSession::create([
            'user_id' => $user->id, 'conversation_id' => $conversation->id,
            'engine_session_id' => 'eng_crash', 'status' => 'running', 'store_id' => 'on',
            'stores' => [], 'objective' => 'x', 'active_slot' => 1,
        ]);

        $receipt = $this->receipt(['payload' => [
            'delivery_id' => 'dlv_crash', 'session_id' => 'eng_crash',
            'conversation_id' => (string) $conversation->id, 'terminal_seq' => 3,
            'result' => ['outcome' => 'completed', 'error_code' => null],
            'assistant_part' => [
                'type' => 'tool-live_results', 'state' => 'output-available',
                'output' => ['products' => [[
                    'store' => 'On', 'store_id' => 'on', 'title' => 'A',
                    'url' => 'https://x.test/1', 'image' => null,
                    'current_price' => null, 'list_price' => null,
                    'availability' => null, 'observed_at' => null,
                ]]],
            ],
        ]]);

        // No dispatch ever happened — the drainer is the only thing that runs it.
        (new ProcessLiveShoppingResultJob($receipt->id))->handle();
        // And a second run (drainer racing a late fast-path dispatch) is a no-op.
        (new ProcessLiveShoppingResultJob($receipt->id))->handle();

        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame('processed', $receipt->fresh()->status);
        $this->assertNull(LiveShoppingSession::first()->active_slot);
    }

    public function test_it_respects_the_batch_limit(): void
    {
        Queue::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->receipt();
        }

        $this->artisan('boxly:live-shopping-drain', ['--limit' => 2])->assertSuccessful();

        Queue::assertPushed(ProcessLiveShoppingResultJob::class, 2);
    }

    public function test_the_drainer_is_a_no_op_when_the_feature_is_disabled(): void
    {
        Queue::fake();
        $this->configureEngine(['enabled' => false]);
        $this->receipt();

        $this->artisan('boxly:live-shopping-drain')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
