<?php

namespace Tests\Feature\LiveShopping;

use App\Models\LiveShoppingWebhookReceipt;
use Tests\LiveShoppingTestCase;
use Tests\LiveShopping\Concerns\SignsDeliveries;

/**
 * The negative matrix for TerminalWebhookV1 / ProductV1.
 *
 * Everything here must be rejected BEFORE anything is persisted. A partially
 * accepted delivery is worse than a rejected one: the engine is told "accepted"
 * and the customer sees whatever survived validation.
 */
class WebhookBoundaryTest extends LiveShoppingTestCase
{
    use SignsDeliveries;

    private function assertRejected(array $body, string $why): void
    {
        $this->deliver($body)->assertStatus(422);
        $this->assertSame(0, LiveShoppingWebhookReceipt::count(), $why);
    }

    /** Bytes are bounded before hashing or decoding — neither should chew on this. */
    public function test_an_oversized_body_is_rejected_before_hashing(): void
    {
        $body = $this->body();
        $body['occurred_at'] = str_repeat('a', 300 * 1024);

        $this->deliver($body)->assertStatus(413);
        $this->assertSame(0, LiveShoppingWebhookReceipt::count());
    }

    public function test_an_unknown_top_level_key_is_rejected(): void
    {
        $this->assertRejected($this->body(['surprise' => 1]), 'unknown top-level key');
    }

    public function test_malformed_identifiers_are_rejected(): void
    {
        foreach (['delivery_id', 'session_id', 'conversation_id'] as $field) {
            foreach (['', 'has spaces', str_repeat('a', 201), "with\nnewline"] as $bad) {
                $this->assertRejected($this->body([$field => $bad]), "{$field} = " . json_encode($bad));
            }
        }
    }

    /** 0 is not a terminal sequence, and neither is a float or a string. */
    public function test_terminal_seq_must_be_a_positive_integer(): void
    {
        foreach ([0, -1, '10', 10.5, null] as $bad) {
            $this->assertRejected($this->body(['terminal_seq' => $bad]), json_encode($bad));
        }
    }

    public function test_occurred_at_must_be_a_parseable_timestamp(): void
    {
        foreach (['', 'not a date', 12345] as $bad) {
            $this->assertRejected($this->body(['occurred_at' => $bad]), json_encode($bad));
        }
    }

    public function test_an_unknown_result_key_is_rejected(): void
    {
        $body = $this->body();
        $body['result']['extra'] = 1;

        $this->assertRejected($body, 'unknown result key');
    }

    public function test_a_malformed_error_code_is_rejected(): void
    {
        $body = $this->body();
        $body['result']['error_code'] = 'has spaces and <html>';

        $this->assertRejected($body, 'error_code shape');
    }

    public function test_an_unknown_assistant_part_key_is_rejected(): void
    {
        $body = $this->body();
        $body['assistant_part']['toolCallId'] = 'x';

        $this->assertRejected($body, 'assistant_part is a closed shape');
    }

    public function test_a_wrong_assistant_part_state_is_rejected(): void
    {
        $body = $this->body();
        $body['assistant_part']['state'] = 'input-available';

        $this->assertRejected($body, 'state must be output-available');
    }

    /** An object masquerading as a list would compare equal after encoding. */
    public function test_products_must_be_a_list_not_a_map(): void
    {
        $body = $this->body();
        $body['result']['products'] = ['a' => $body['result']['products'][0]];
        $body['assistant_part']['output']['products'] = ['a' => $body['result']['products']['a']];

        $this->assertRejected($body, 'products must be a list');
    }

    public function test_a_product_with_an_unknown_key_rejects_the_delivery(): void
    {
        $body = $this->body();
        $body['result']['products'][0]['surprise'] = 1;
        $body['assistant_part']['output']['products'] = $body['result']['products'];

        $this->assertRejected($body, 'ProductV1 is a closed shape');
    }

    /**
     * The frozen key set is EXACT: every one of the nine is required, including
     * the three that may hold null. Omitting a key is not the same statement as
     * sending null, and tolerating the difference is how three repos end up
     * with three different validators.
     */
    public function test_a_product_missing_any_frozen_key_rejects_the_delivery(): void
    {
        foreach (\App\Support\ProductV1::KEYS as $field) {
            $body = $this->body();
            unset($body['result']['products'][0][$field]);
            $body['assistant_part']['output']['products'] = $body['result']['products'];

            $this->assertRejected($body, "product.{$field} is required");
        }
    }

    public function test_a_product_with_a_non_strict_observed_at_rejects_the_delivery(): void
    {
        foreach (['2026-09-01 00:00:00', '2026-09-01T00:00:00+00:00', '2026-02-30T00:00:00Z', 'now'] as $value) {
            $body = $this->body();
            $body['result']['products'][0]['observed_at'] = $value;
            $body['assistant_part']['output']['products'] = $body['result']['products'];

            $this->assertRejected($body, "observed_at={$value}");
        }
    }

    public function test_a_product_observed_in_the_future_rejects_the_delivery(): void
    {
        $body = $this->body();
        $body['result']['products'][0]['observed_at'] = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
        $body['assistant_part']['output']['products'] = $body['result']['products'];

        $this->assertRejected($body, 'an observation from the future');
    }

    public function test_a_lowercase_currency_rejects_the_delivery(): void
    {
        $body = $this->body();
        $body['result']['products'][0]['current_price'] = ['amount' => 10.0, 'currency' => 'usd'];
        $body['assistant_part']['output']['products'] = $body['result']['products'];

        $this->assertRejected($body, 'currency must already be uppercase');
    }

    /** Prefix checks would have let these through; parsing does not. */
    public function test_a_product_url_with_credentials_or_control_chars_is_rejected(): void
    {
        foreach (['https://user:pass@x.test/1', "https://x.test/1\n", 'http://x.test/1'] as $bad) {
            $body = $this->body();
            $body['result']['products'][0]['url'] = $bad;
            $body['assistant_part']['output']['products'] = $body['result']['products'];

            $this->assertRejected($body, $bad);
        }
    }

    public function test_a_numeric_string_price_is_rejected_not_coerced(): void
    {
        $body = $this->body();
        $body['result']['products'][0]['current_price'] = ['amount' => '169.99', 'currency' => 'USD'];
        $body['assistant_part']['output']['products'] = $body['result']['products'];

        $this->assertRejected($body, 'money amounts are numbers, not strings');
    }

    public function test_more_than_the_product_cap_is_rejected(): void
    {
        $body = $this->body();
        $one = $body['result']['products'][0];
        $products = [];
        for ($i = 0; $i < 25; $i++) {
            $products[] = array_merge($one, ['url' => "https://www.on.com/p{$i}"]);
        }
        $body['result']['products'] = $products;
        $body['assistant_part']['output']['products'] = $products;

        $this->assertRejected($body, 'over the 24 cap');
    }

    /** The happy path still works after all of the above. */
    public function test_a_well_formed_delivery_is_still_accepted(): void
    {
        $this->deliver($this->body())->assertStatus(202);
        $this->assertSame(1, LiveShoppingWebhookReceipt::count());
    }

    /** A partial_match caveat is a closed, persisted label; anything else is refused. */
    public function test_a_partial_match_caveat_is_accepted_and_persisted_verbatim(): void
    {
        $body = $this->body(['result' => ['outcome' => 'completed', 'products' => $this->body()['result']['products'], 'error_code' => 'partial_match']]);
        $body['assistant_part']['output']['caveat'] = 'partial_match';

        $this->deliver($body)->assertSuccessful();
        $receipt = LiveShoppingWebhookReceipt::first();
        $this->assertNotNull($receipt);
        $this->assertSame('partial_match', $receipt->payload['assistant_part']['output']['caveat'] ?? null);
        // The key SET is the contract; MySQL's JSON column stores object keys in its own order
        // (shorter first), so insertion order is not something a persisted payload can promise.
        $keys = array_keys($receipt->payload['assistant_part']['output']);
        sort($keys);
        $this->assertSame(['caveat', 'products'], $keys);
    }

    public function test_an_unknown_caveat_is_rejected(): void
    {
        $body = $this->body();
        $body['assistant_part']['output']['caveat'] = 'looks_great';
        $this->assertRejected($body, 'caveat is a closed vocabulary');
    }

    public function test_a_delivery_without_a_caveat_keeps_the_exact_frozen_output_shape(): void
    {
        $this->deliver($this->body())->assertSuccessful();
        $this->assertSame(['products'], array_keys(LiveShoppingWebhookReceipt::first()->payload['assistant_part']['output']));
    }

    /** L2 (multi-store): per-store outcomes ride on result and on the part, in the closed shape, and must agree. */
    public function test_per_store_outcomes_are_accepted_in_the_closed_shape_and_must_agree(): void
    {
        $stores = [['store_id' => 'on', 'outcome' => 'completed', 'error_code' => null], ['store_id' => 'target', 'outcome' => 'failed', 'error_code' => 'store_blocked']];

        // Refusals first (assertRejected expects an empty receipt table), the accepted delivery last.
        $mismatch = $this->body();
        $mismatch['result']['stores'] = $stores;
        $this->assertRejected($mismatch, 'stores on the result but not on the part');

        $unknownKey = $this->body();
        $unknownKey['result']['stores'] = [['store_id' => 'on', 'outcome' => 'completed', 'error_code' => null, 'products' => []]];
        $unknownKey['assistant_part']['output']['stores'] = $unknownKey['result']['stores'];
        $this->assertRejected($unknownKey, 'an unknown key inside a store entry');

        $badOutcome = $this->body();
        $badOutcome['result']['stores'] = [['store_id' => 'on', 'outcome' => 'running', 'error_code' => null]];
        $badOutcome['assistant_part']['output']['stores'] = $badOutcome['result']['stores'];
        $this->assertRejected($badOutcome, 'a non-terminal per-store outcome');

        $body = $this->body();
        $body['result']['stores'] = $stores;
        $body['assistant_part']['output']['stores'] = $stores;
        $this->deliver($body)->assertSuccessful();
        $this->assertSame(1, LiveShoppingWebhookReceipt::count());
        // Entry key order is MySQL's (JSON columns reorder object keys); the values are the contract.
        $canonical = fn (array $list) => array_map(function (array $e) { ksort($e); return $e; }, $list);
        $this->assertSame($canonical($stores), $canonical(LiveShoppingWebhookReceipt::first()->payload['result']['stores']));
    }
}
