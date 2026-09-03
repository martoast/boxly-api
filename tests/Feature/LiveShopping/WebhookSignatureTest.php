<?php

namespace Tests\Feature\LiveShopping;

use App\Jobs\ProcessLiveShoppingResultJob;
use App\Models\LiveShoppingWebhookReceipt;
use Illuminate\Support\Facades\Queue;
use Tests\LiveShoppingTestCase;
use Tests\LiveShopping\Concerns\SignsDeliveries;

class WebhookSignatureTest extends LiveShoppingTestCase
{
    use SignsDeliveries;

    public function test_a_valid_delivery_is_accepted_with_the_exact_ack_body(): void
    {
        Queue::fake();

        $this->deliver($this->body())
            ->assertStatus(202)
            ->assertExactJson(['ok' => true, 'data' => [
                'schema_version' => 1,
                'delivery_id'    => 'dlv_1',
                'accepted'       => true,
            ]]);

        Queue::assertPushed(ProcessLiveShoppingResultJob::class);
    }

    public function test_unknown_key_id_is_403(): void
    {
        $this->deliver($this->body(), ['X-Boxly-Key-Id' => 'nope'])->assertStatus(403);
        $this->assertSame(0, LiveShoppingWebhookReceipt::count());
    }

    public function test_missing_timestamp_is_403(): void
    {
        $this->deliver($this->body(), ['X-Boxly-Timestamp' => ''])->assertStatus(403);
    }

    public function test_stale_timestamp_is_403(): void
    {
        $this->deliver($this->body(), timestamp: time() - 301)->assertStatus(403);
    }

    public function test_future_timestamp_is_403(): void
    {
        $this->deliver($this->body(), timestamp: time() + 301)->assertStatus(403);
    }

    public function test_missing_nonce_is_403(): void
    {
        $this->deliver($this->body(), ['X-Boxly-Nonce' => ''])->assertStatus(403);
    }

    public function test_malformed_nonce_is_403(): void
    {
        $this->deliver($this->body(), ['X-Boxly-Nonce' => 'has spaces'])->assertStatus(403);
    }

    /**
     * The signature covers the body's HASH, not the body. So a body altered
     * after signing is caught by the content-hash check — which runs BEFORE
     * json_decode, meaning even unparseable garbage 403s rather than 500s.
     */
    public function test_altered_body_is_403(): void
    {
        $signed = $this->signedHeaders('{"a":1}');

        $this->postJson('/live-shopping/webhook', ['b' => 2], $signed)->assertStatus(403);
    }

    public function test_altered_and_unparseable_body_is_403_not_500(): void
    {
        $signed = $this->signedHeaders('{"a":1}');

        $this->call('POST', '/live-shopping/webhook', [], [], [], $this->serverHeaders($signed), 'not json at all')
            ->assertStatus(403);
    }

    /**
     * Each signed element gets its own case: rewriting any one of them while
     * the others stay valid must fail. That is the property the canonical
     * string exists to guarantee.
     */
    public function test_rewriting_the_timestamp_alone_is_403(): void
    {
        $raw = json_encode($this->body());
        $headers = $this->signedHeaders($raw);
        $headers['X-Boxly-Timestamp'] = (string) (time() - 5);   // still in-skew, but not what was signed

        $this->call('POST', '/live-shopping/webhook', [], [], [], $this->serverHeaders($headers), $raw)
            ->assertStatus(403);
    }

    public function test_rewriting_the_nonce_alone_is_403(): void
    {
        $raw = json_encode($this->body());
        $headers = $this->signedHeaders($raw);
        $headers['X-Boxly-Nonce'] = 'a-different-nonce';

        $this->call('POST', '/live-shopping/webhook', [], [], [], $this->serverHeaders($headers), $raw)
            ->assertStatus(403);
    }

    public function test_rewriting_the_body_hash_alone_is_403(): void
    {
        $raw = json_encode($this->body());
        $headers = $this->signedHeaders($raw);
        $headers['X-Boxly-Content-SHA256'] = hash('sha256', 'something else');

        $this->call('POST', '/live-shopping/webhook', [], [], [], $this->serverHeaders($headers), $raw)
            ->assertStatus(403);
    }

    public function test_signature_without_the_v1_prefix_is_403(): void
    {
        $raw = json_encode($this->body());
        $headers = $this->signedHeaders($raw);
        $headers['X-Boxly-Signature'] = substr($headers['X-Boxly-Signature'], 3);   // strip "v1="

        $this->call('POST', '/live-shopping/webhook', [], [], [], $this->serverHeaders($headers), $raw)
            ->assertStatus(403);
    }

    public function test_disabled_feature_is_404(): void
    {
        $this->configureEngine(['enabled' => false]);

        $this->deliver($this->body())->assertStatus(404);
        $this->assertSame(0, LiveShoppingWebhookReceipt::count());
    }
}
