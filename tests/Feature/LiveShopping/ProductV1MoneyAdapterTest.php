<?php

namespace Tests\Feature\LiveShopping;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use Tests\LiveShoppingTestCase;

/**
 * ProductV1 money -> the product rail.
 *
 * Two failures this suite exists to catch, because NEITHER throws and both look
 * shipped: without an adapter the rail renders priceless, and with a naive
 * `?? $p['current_price']` it renders "[object Object]" — worse, because it
 * looks like data.
 *
 * Asserted through GET /conversations/{id}, i.e. the rail the customer sees.
 */
class ProductV1MoneyAdapterTest extends LiveShoppingTestCase
{
    private function rail(array $product): array
    {
        $user = User::factory()->createQuietly();
        $conversation = Conversation::create(['user_id' => $user->id]);

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'assistant',
            'content'         => ['parts' => [[
                'type'   => 'tool-live_results',
                'state'  => 'output-available',
                'output' => ['products' => [$product]],
            ]]],
        ]);

        return $this->actingAs($user)
            ->getJson("/conversations/{$conversation->id}")
            ->json('data.products.0');
    }

    private function product(array $overrides = []): array
    {
        return array_merge([
            'store'    => 'On',
            'store_id' => 'on',
            'title'    => 'Cloudmonster 2',
            'url'      => 'https://www.on.com/cm2',
        ], $overrides);
    }

    public function test_positive_case_extracts_both_prices_and_flags_the_sale(): void
    {
        $row = $this->rail($this->product([
            'current_price' => ['amount' => 169.99, 'currency' => 'USD'],
            'list_price'    => ['amount' => 189.99, 'currency' => 'USD'],
        ]));

        $this->assertSame('169.99', $row['price']);
        $this->assertSame('189.99', $row['was']);
        $this->assertTrue($row['on_sale']);
        // Never the raw object: that is the "[object Object]" failure.
        $this->assertIsString($row['price']);
    }

    public function test_missing_list_price_still_yields_a_price(): void
    {
        $row = $this->rail($this->product([
            'current_price' => ['amount' => 99.0, 'currency' => 'USD'],
        ]));

        $this->assertSame('99.00', $row['price']);
        $this->assertNull($row['was']);
        $this->assertFalse($row['on_sale']);
    }

    public function test_malformed_amounts_yield_null_never_an_object(): void
    {
        // INF/NAN are covered in ProductV1MoneyUnitTest instead: they have no
        // JSON literal, so they cannot survive a round trip through the
        // message's JSON column and cannot arrive from the engine either.
        foreach ([['x' => 1], 'not a number', -5, null, true] as $amount) {
            $row = $this->rail($this->product([
                'current_price' => ['amount' => $amount, 'currency' => 'USD'],
            ]));

            $this->assertNull($row['price'], 'amount ' . json_encode($amount) . ' should not render');
        }
    }

    /**
     * We do not convert currencies in a display adapter. Showing an MXN number
     * in a USD-shaped rail would be a lie the customer acts on.
     */
    public function test_non_usd_is_dropped(): void
    {
        $row = $this->rail($this->product([
            'current_price' => ['amount' => 3400.0, 'currency' => 'MXN'],
            'list_price'    => ['amount' => 3900.0, 'currency' => 'MXN'],
        ]));

        $this->assertNull($row['price']);
        $this->assertNull($row['was']);
        $this->assertFalse($row['on_sale']);
    }

    /** Comparing across currencies is meaningless, so there is no sale to claim. */
    public function test_currency_mismatch_drops_the_list_price_and_the_badge(): void
    {
        $row = $this->rail($this->product([
            'current_price' => ['amount' => 169.99, 'currency' => 'USD'],
            'list_price'    => ['amount' => 3900.0, 'currency' => 'MXN'],
        ]));

        $this->assertSame('169.99', $row['price']);
        $this->assertNull($row['was']);
        $this->assertFalse($row['on_sale']);
    }

    public function test_equal_prices_are_not_a_sale(): void
    {
        $row = $this->rail($this->product([
            'current_price' => ['amount' => 100.0, 'currency' => 'USD'],
            'list_price'    => ['amount' => 100.0, 'currency' => 'USD'],
        ]));

        $this->assertFalse($row['on_sale']);
    }

    public function test_lowercase_currency_still_normalizes_to_usd(): void
    {
        $row = $this->rail($this->product([
            'current_price' => ['amount' => 42.50, 'currency' => ' usd '],
        ]));

        $this->assertSame('42.50', $row['price']);
    }

    /**
     * "12.50" and 12.50 are different contracts. Coercing both means we never
     * find out which one the engine actually sends.
     */
    public function test_a_numeric_string_amount_is_not_coerced(): void
    {
        $row = $this->rail($this->product([
            'current_price' => ['amount' => '42.50', 'currency' => 'USD'],
        ]));

        $this->assertNull($row['price']);
    }

    /**
     * The adapter must be unreachable for every part written by existing tools.
     * Legacy keys always win, even when ProductV1 money is also present.
     */
    public function test_legacy_keys_take_precedence(): void
    {
        $row = $this->rail($this->product([
            'price'         => '$1.00',
            'was'           => '$2.00',
            'on_sale'       => false,
            'current_price' => ['amount' => 169.99, 'currency' => 'USD'],
            'list_price'    => ['amount' => 189.99, 'currency' => 'USD'],
        ]));

        $this->assertSame('$1.00', $row['price']);
        $this->assertSame('$2.00', $row['was']);
        $this->assertFalse($row['on_sale']);
    }

    /** An ordinary legacy-only part is completely untouched by this change. */
    public function test_a_legacy_only_part_is_unchanged(): void
    {
        $row = $this->rail([
            'title' => 'Old thing', 'url' => 'https://x.test/1',
            'price' => '$10.00', 'store' => 'Nike',
        ]);

        $this->assertSame('$10.00', $row['price']);
        $this->assertNull($row['was']);
        $this->assertFalse($row['on_sale']);
    }
}
