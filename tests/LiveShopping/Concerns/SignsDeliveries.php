<?php

namespace Tests\LiveShopping\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * Builds correctly-signed EngineV1 deliveries.
 *
 * Kept in one place so every webhook test signs the SAME way the engine will:
 * canonical UTF-8 bytes "v1\n" + timestamp + "\n" + nonce + "\n" + body_sha256.
 * If this helper and the controller ever disagree, every webhook test fails
 * loudly rather than one test quietly asserting the wrong contract.
 */
trait SignsDeliveries
{
    protected function body(array $overrides = []): array
    {
        $products = $overrides['products'] ?? [[
            'store'         => 'On',
            'store_id'      => 'on',
            'title'         => 'Cloudmonster 2',
            'url'           => 'https://www.on.com/cloudmonster-2',
            'image'         => 'https://cdn.on.com/cm2.jpg',
            'current_price' => ['amount' => 169.99, 'currency' => 'USD'],
            'list_price'    => ['amount' => 189.99, 'currency' => 'USD'],
            'availability'  => 'in_stock',
            // Derived, not a literal: ProductV1 rejects an observation more than
            // 5 minutes in the future, so a hardcoded date silently becomes a
            // failing fixture the moment the clock passes it.
            'observed_at'   => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
        ]];
        unset($overrides['products']);

        return array_merge([
            'schema_version'  => 1,
            'delivery_id'     => 'dlv_1',
            'session_id'      => 'eng_1',
            'terminal_seq'    => 10,
            'conversation_id' => '1',
            'occurred_at'     => '2026-09-01T00:00:00Z',
            'result'          => [
                'outcome'    => 'completed',
                'products'   => $products,
                'error_code' => null,
            ],
            'assistant_part'  => [
                'type'   => 'tool-live_results',
                'state'  => 'output-available',
                'output' => ['products' => $products],
            ],
        ], $overrides);
    }

    /**
     * The payload shape the CONTROLLER stores on the receipt, so job-level tests
     * exercise exactly what the webhook would have written.
     */
    protected function storedPayload(array $overrides = []): array
    {
        $body = $this->body($overrides);

        return [
            'delivery_id'     => $body['delivery_id'],
            'session_id'      => $body['session_id'],
            'conversation_id' => $body['conversation_id'],
            'terminal_seq'    => $body['terminal_seq'],
            'occurred_at'     => $body['occurred_at'],
            'result'          => array_merge([
                'outcome'    => $body['result']['outcome'],
                'error_code' => $body['result']['error_code'],
            ], isset($body['result']['stores']) ? ['stores' => $body['result']['stores']] : []),
            'assistant_part'  => $body['assistant_part'],
        ];
    }

    protected function signedHeaders(string $raw, ?int $timestamp = null, string $nonce = 'nonce-1', string $keyId = 'k1'): array
    {
        $timestamp = (string) ($timestamp ?? time());
        $hash = hash('sha256', $raw);
        $canonical = "v1\n" . $timestamp . "\n" . $nonce . "\n" . $hash;
        $secret = 'webhook-secret';

        return [
            'X-Boxly-Key-Id'         => $keyId,
            'X-Boxly-Timestamp'      => $timestamp,
            'X-Boxly-Nonce'          => $nonce,
            'X-Boxly-Content-SHA256' => $hash,
            'X-Boxly-Signature'      => 'v1=' . hash_hmac('sha256', $canonical, $secret),
        ];
    }

    protected function deliver(array $body, array $headerOverrides = [], ?int $timestamp = null, string $nonce = 'nonce-1'): TestResponse
    {
        $raw = json_encode($body);
        $headers = array_merge($this->signedHeaders($raw, $timestamp, $nonce), $headerOverrides);

        return $this->call('POST', '/live-shopping/webhook', [], [], [], $this->serverHeaders($headers), $raw);
    }

    /** Laravel's ->call() wants HTTP_-prefixed server vars, not plain headers. */
    protected function serverHeaders(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $server;
    }
}
