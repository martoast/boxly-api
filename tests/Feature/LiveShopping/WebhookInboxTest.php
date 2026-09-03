<?php

namespace Tests\Feature\LiveShopping;

use App\Jobs\ProcessLiveShoppingResultJob;
use App\Models\LiveShoppingWebhookReceipt;
use Illuminate\Support\Facades\Queue;
use Tests\LiveShoppingTestCase;
use Tests\LiveShopping\Concerns\SignsDeliveries;

/**
 * The durable inbox: replay vs conflict, and the promise the 202 makes.
 */
class WebhookInboxTest extends LiveShoppingTestCase
{
    use SignsDeliveries;

    public function test_the_receipt_is_committed_before_the_ack(): void
    {
        Queue::fake();

        $this->deliver($this->body())->assertStatus(202);

        $receipt = LiveShoppingWebhookReceipt::firstWhere('delivery_id', 'dlv_1');
        $this->assertNotNull($receipt);
        $this->assertSame('received', $receipt->status);
        $this->assertSame(hash('sha256', json_encode($this->body())), $receipt->content_sha256);
        $this->assertSame(10, $receipt->terminal_seq);
        // The payload must survive the response: the job gets an id, not a body.
        $this->assertNotEmpty($receipt->payload['assistant_part']['output']['products']);
        // Stored as the EXACT frozen part shape, with nothing invented.
        $this->assertSame(
            ['type', 'state', 'output'],
            array_keys($receipt->payload['assistant_part']),
        );
    }

    /**
     * A replay is never an error. An engine retrying a delivery it is unsure
     * about is doing the right thing, and must get the same ack it got first.
     */
    public function test_an_exact_replay_gets_the_same_ack_and_no_second_receipt(): void
    {
        Queue::fake();

        $this->deliver($this->body())->assertStatus(202);
        $this->deliver($this->body())
            ->assertStatus(202)
            ->assertJsonPath('data.accepted', true);

        $this->assertSame(1, LiveShoppingWebhookReceipt::count());
        // Only the first delivery creates work.
        Queue::assertPushed(ProcessLiveShoppingResultJob::class, 1);
    }

    /**
     * Two different bodies claiming one delivery id means one of them is wrong.
     * Treating that as a duplicate would silently drop real data.
     */
    public function test_same_delivery_id_with_a_different_body_is_a_409(): void
    {
        Queue::fake();

        $this->deliver($this->body())->assertStatus(202);

        $this->deliver($this->body(['terminal_seq' => 11]))
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        $this->assertSame(1, LiveShoppingWebhookReceipt::count());
        Queue::assertPushed(ProcessLiveShoppingResultJob::class, 1);
    }

    /**
     * The case that killed the read-then-decide design. The receipt already
     * exists (as if a concurrent request won the race); the conflict must be
     * decided by the unique index on write, so it is DETERMINISTIC.
     */
    public function test_conflict_is_deterministic_against_a_pre_existing_receipt(): void
    {
        Queue::fake();

        LiveShoppingWebhookReceipt::create([
            'delivery_id'    => 'dlv_1',
            'content_sha256' => hash('sha256', 'a completely different body'),
            'payload'        => ['products' => []],
            'status'         => 'received',
            'received_at'    => now(),
        ]);

        $this->deliver($this->body())->assertStatus(409);

        $this->assertSame(1, LiveShoppingWebhookReceipt::count());
        Queue::assertNothingPushed();
    }

    /** Nothing half-accepted: a rejected body leaves no trace at all. */
    public function test_an_invalid_body_leaves_no_receipt(): void
    {
        Queue::fake();

        $this->deliver($this->body(['terminal_seq' => -1]))->assertStatus(422);

        $this->assertSame(0, LiveShoppingWebhookReceipt::count());
        Queue::assertNothingPushed();
    }

    public function test_unknown_schema_version_is_rejected(): void
    {
        $this->deliver($this->body(['schema_version' => 99]))->assertStatus(422);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        foreach (['delivery_id', 'session_id', 'conversation_id'] as $field) {
            $body = $this->body();
            unset($body[$field]);
            $this->deliver($body)->assertStatus(422);
        }
    }

    public function test_an_unknown_outcome_is_rejected(): void
    {
        $body = $this->body();
        $body['result']['outcome'] = 'exploded';

        $this->deliver($body)->assertStatus(422);
    }

    public function test_a_wrong_assistant_part_type_is_rejected(): void
    {
        $body = $this->body();
        $body['assistant_part']['type'] = 'tool-live_verify';

        $this->deliver($body)->assertStatus(422);
    }

    /**
     * Two projections of one result are two chances to be wrong, and the one we
     * persist is the one the customer sees. So we persist NEITHER.
     */
    public function test_a_divergent_assistant_part_is_rejected(): void
    {
        Queue::fake();

        $body = $this->body();
        $body['assistant_part']['output']['products'][0]['title'] = 'Something else entirely';

        $this->deliver($body)->assertStatus(422);

        $this->assertSame(0, LiveShoppingWebhookReceipt::count());
        Queue::assertNothingPushed();
    }
}
