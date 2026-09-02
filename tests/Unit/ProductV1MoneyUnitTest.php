<?php

namespace Tests\Unit;

use App\Support\ProductV1;
use PHPUnit\Framework\TestCase;

/**
 * Direct coverage of ProductV1's guards.
 *
 * boundStrict() returns null for a malformed LIST rather than silently dropping
 * items: a product we cannot parse means the delivery is not what we think it
 * is, and quietly shrinking the list would hide that from the customer and from
 * the assistant_part/result.products agreement check.
 */
class ProductV1MoneyUnitTest extends TestCase
{
    /**
     * The frozen 9-key ProductV1. Every key is present because the boundary
     * requires the EXACT set — `image`, `current_price` and `list_price` may be
     * null, but omitting the key is itself a rejection.
     */
    private function product(array $overrides = []): array
    {
        return array_merge([
            'store'         => 'On',
            'store_id'      => 'on',
            'title'         => 'Cloudmonster 2',
            'url'           => 'https://www.on.com/cm2',
            'image'         => null,
            'current_price' => null,
            'list_price'    => null,
            'availability'  => 'unknown',
            // Derived, never a literal date: an observation more than 5 minutes
            // in the future is rejected, so a hardcoded one rots into a failure.
            'observed_at'   => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
        ], $overrides);
    }

    /** ---- money() ------------------------------------------------------ */

    public function test_non_finite_amounts_are_rejected(): void
    {
        foreach ([INF, -INF, NAN] as $amount) {
            $money = ProductV1::money(['current_price' => ['amount' => $amount, 'currency' => 'USD']]);

            $this->assertNull($money['price']);
            $this->assertFalse($money['on_sale']);
        }
    }

    public function test_negative_amounts_are_rejected(): void
    {
        $this->assertNull(ProductV1::money([
            'current_price' => ['amount' => -1.0, 'currency' => 'USD'],
        ])['price']);
    }

    public function test_a_missing_currency_is_rejected(): void
    {
        $this->assertNull(ProductV1::money(['current_price' => ['amount' => 10.0]])['price']);
    }

    /** ---- boundStrict() ------------------------------------------------ */

    public function test_a_valid_product_round_trips_with_the_frozen_key_set(): void
    {
        $bounded = ProductV1::boundStrict([$this->product([
            'current_price' => ['amount' => 12.5, 'currency' => 'USD'],
            'availability'  => 'in_stock',
        ])]);

        $this->assertCount(1, $bounded);
        // Rebuilt in the frozen order, not echoed back in the caller's order.
        $this->assertSame(ProductV1::KEYS, array_keys($bounded[0]));
        $this->assertSame(['amount' => 12.5, 'currency' => 'USD'], $bounded[0]['current_price']);
    }

    /**
     * EXACT key set, not merely closed: a MISSING key rejects too. "Absent" and
     * "explicitly null" are different statements, and allowing a producer to
     * omit a key is how three repos drift into three different validators.
     */
    public function test_every_frozen_key_is_required_even_the_nullable_ones(): void
    {
        foreach (ProductV1::KEYS as $key) {
            $p = $this->product();
            unset($p[$key]);

            $this->assertNull(ProductV1::boundStrict([$p]), "{$key} must be required");
        }
    }

    public function test_the_nullable_keys_accept_an_explicit_null(): void
    {
        $bounded = ProductV1::boundStrict([$this->product([
            'image' => null, 'current_price' => null, 'list_price' => null,
        ])]);

        $this->assertCount(1, $bounded);
        $this->assertNull($bounded[0]['image']);
        $this->assertNull($bounded[0]['current_price']);
    }

    /**
     * `strtotime()` alone accepts "now", "+1 day" and a dozen offset spellings,
     * so two producers could both be "valid" while writing different formats.
     */
    public function test_observed_at_must_be_strict_utc_rfc3339(): void
    {
        $bad = [
            '2026-09-01 00:00:00',          // space, not T
            '2026-09-01T00:00:00',          // no zone
            '2026-09-01T00:00:00+00:00',    // offset spelling, not Z
            '2026-09-01T00:00:00.123456Z',  // more than 3 fractional digits
            // Regex-shaped but not real instants. strtotime() does NOT catch
            // these — it rolls Feb 30 over to March 2 — so the calendar and
            // clock components are checked explicitly.
            '2026-02-30T00:00:00Z',
            '2025-02-29T00:00:00Z',         // 2025 is not a leap year
            '2026-13-01T00:00:00Z',
            '2026-01-01T25:00:00Z',
            '2026-01-01T00:60:00Z',
            'now',
            '',
        ];

        foreach ($bad as $value) {
            $this->assertNull(
                ProductV1::boundStrict([$this->product(['observed_at' => $value])]),
                $value,
            );
        }

        // Milliseconds ARE allowed, up to three digits.
        $this->assertNotNull(ProductV1::boundStrict([$this->product([
            'observed_at' => gmdate('Y-m-d\TH:i:s', time() - 60) . '.250Z',
        ])]));
    }

    public function test_an_observation_from_the_future_is_rejected(): void
    {
        // Inside the tolerated skew.
        $this->assertNotNull(ProductV1::boundStrict([$this->product([
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 60),
        ])]));

        // Beyond it: a broken clock or a fabricated timestamp, not a fact.
        $this->assertNull(ProductV1::boundStrict([$this->product([
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        ])]));
    }

    public function test_over_the_cap_is_rejected_not_truncated(): void
    {
        $products = [];
        for ($i = 0; $i < ProductV1::MAX_PRODUCTS + 1; $i++) {
            $products[] = $this->product(['url' => "https://x.test/{$i}"]);
        }

        $this->assertNull(ProductV1::boundStrict($products));
    }

    public function test_required_fields_reject_blanks_and_wrong_types(): void
    {
        foreach (['title', 'store', 'store_id', 'url'] as $field) {
            foreach (['', '   ', null, 123, []] as $value) {
                $this->assertNull(
                    ProductV1::boundStrict([$this->product([$field => $value])]),
                    "{$field} = " . json_encode($value),
                );
            }
        }
    }

    public function test_an_unknown_key_rejects_the_delivery(): void
    {
        $this->assertNull(ProductV1::boundStrict([$this->product(['surprise' => 'x'])]));
    }

    /**
     * str_starts_with($u, 'https://') accepts credentials and control
     * characters, which is exactly why the URL is parsed instead.
     */
    public function test_urls_are_parsed_not_prefix_matched(): void
    {
        $bad = [
            'http://x.test/1',
            'https://user:pass@x.test/1',
            "https://x.test/1\n",
            'https:///nohost',
            'https://x.test/1 with space',
            'javascript:alert(1)',
            'https://-bad-.host./x',
            // A fragment is client-side only and never reaches a fetcher, so a
            // URL carrying one is not the URL it claims to be.
            'https://x.test/1#frag',
            str_repeat('https://x.test/', 200),   // over 2048
        ];

        foreach ($bad as $url) {
            $this->assertNull(ProductV1::boundStrict([$this->product(['url' => $url])]), $url);
        }
    }

    /** CDN image and product URLs legitimately carry a query string. */
    public function test_a_query_string_is_allowed_on_url_and_image(): void
    {
        $bounded = ProductV1::boundStrict([$this->product([
            'url'   => 'https://x.test/p?variant=42&utm=x',
            'image' => 'https://cdn.x.test/i.jpg?w=800&fm=webp',
        ])]);

        $this->assertNotNull($bounded);
        $this->assertSame('https://x.test/p?variant=42&utm=x', $bounded[0]['url']);
        $this->assertSame('https://cdn.x.test/i.jpg?w=800&fm=webp', $bounded[0]['image']);
    }

    public function test_a_bad_image_url_rejects_the_delivery(): void
    {
        foreach (['data:image/png;base64,AAAA', 'http://x.test/i.jpg', 'https://x.test/i.jpg#f'] as $image) {
            $this->assertNull(ProductV1::boundStrict([$this->product(['image' => $image])]));
        }

        $ok = ProductV1::boundStrict([$this->product(['image' => 'https://x.test/i.jpg'])]);
        $this->assertSame('https://x.test/i.jpg', $ok[0]['image']);
    }

    /**
     * Required and enumerated — NOT nullable. "We could not read the stock
     * state" is spelled `unknown`; spelling it null loses the difference
     * between a state the engine failed to read and one it never looked at.
     */
    public function test_availability_is_a_required_enum(): void
    {
        foreach (['maybe', null, '', 'IN_STOCK', 1] as $value) {
            $this->assertNull(
                ProductV1::boundStrict([$this->product(['availability' => $value])]),
                json_encode($value),
            );
        }

        foreach (ProductV1::AVAILABILITY as $value) {
            $this->assertNotNull(ProductV1::boundStrict([$this->product(['availability' => $value])]), $value);
        }
    }

    public function test_money_must_be_exact_numbers_with_an_iso_currency(): void
    {
        $bad = [
            ['amount' => '12.50', 'currency' => 'USD'],   // string, not coerced
            ['amount' => 12.50, 'currency' => 'US'],      // not ISO 4217 shape
            ['amount' => -1, 'currency' => 'USD'],
            ['amount' => 12.50],                          // no currency
            ['amount' => 12.50, 'currency' => 'USD', 'extra' => 1],
            // Uppercase at the boundary, NOT lowercased-then-normalized:
            // accepting "usd" and storing "USD" lets the three repos disagree
            // about the wire format indefinitely without anyone noticing.
            ['amount' => 12.50, 'currency' => 'usd'],
            ['amount' => 12.50, 'currency' => 'Usd'],
            'not an object',
        ];

        foreach ($bad as $money) {
            $this->assertNull(
                ProductV1::boundStrict([$this->product(['current_price' => $money])]),
                json_encode($money),
            );
        }
    }

    public function test_over_long_strings_reject_rather_than_truncate(): void
    {
        // Frozen bounds: title 300, store 120.
        $this->assertNotNull(ProductV1::boundStrict([$this->product(['title' => str_repeat('a', 300)])]));
        $this->assertNull(ProductV1::boundStrict([$this->product(['title' => str_repeat('a', 301)])]));

        $this->assertNotNull(ProductV1::boundStrict([$this->product(['store' => str_repeat('b', 120)])]));
        $this->assertNull(ProductV1::boundStrict([$this->product(['store' => str_repeat('b', 121)])]));
    }

    /**
     * Nothing the caller owns survives the boundary — the returned list is
     * rebuilt from validated scalars, so a later mutation of the input cannot
     * reach through into what we persist.
     */
    public function test_the_result_is_a_copy_not_a_reference(): void
    {
        $input = [$this->product(['title' => 'Original'])];
        $bounded = ProductV1::boundStrict($input);

        $input[0]['title'] = 'Mutated';

        $this->assertSame('Original', $bounded[0]['title']);
    }

    public function test_a_malformed_store_id_is_rejected(): void
    {
        $this->assertNull(ProductV1::boundStrict([$this->product(['store_id' => 'NOT A SLUG'])]));
    }
}
