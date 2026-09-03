<?php

namespace App\Support;

/**
 * The engine's ProductV1 shape: strict validation on the way IN, money
 * extraction on the way OUT.
 *
 * Two jobs, deliberately kept together because they must agree:
 *
 *  - bound()   runs before we persist an engine-supplied assistant_part. The
 *              engine is trusted, but a trusted upstream that goes wrong must
 *              not write an unbounded or malformed blob into a conversation.
 *  - money()   runs in the READ path (ConversationController::deriveProducts).
 *              ProductV1 carries {amount, currency} objects, not the scalar
 *              price/was the rail reads, so without this every live result
 *              renders priceless — and passing the object through raw would
 *              render "[object Object]", which is worse because it looks like
 *              data.
 */
class ProductV1
{
    /** Per-delivery cap. Not a conversation cap — deriveProducts keeps its own. */
    public const MAX_PRODUCTS = 24;

    /**
     * The frozen P1 key set — EXACT, not merely closed.
     *
     * All nine must be present on every product. `image`, `current_price` and
     * `list_price` may be null, but the KEY is still required: "absent" and
     * "explicitly null" are different statements, and letting a producer omit a
     * key is how three repos drift into three different validators (which is
     * exactly what this freeze was called to fix). A missing key or an extra one
     * rejects the whole delivery.
     */
    public const KEYS = [
        'store', 'store_id', 'title', 'url', 'image',
        'current_price', 'list_price', 'availability', 'observed_at',
    ];

    public const AVAILABILITY = ['in_stock', 'out_of_stock', 'preorder', 'backorder', 'unknown'];

    /** Frozen field bounds. */
    private const MAX_STORE = 120;
    private const MAX_TITLE = 300;
    private const MAX_URL   = 2048;

    /**
     * How far into the future an `observed_at` may sit before we call it wrong.
     * Small clock skew between the engine and us is normal; minutes are not.
     */
    private const MAX_FUTURE_SECONDS = 300;

    /**
     * Validate and bound one engine product list.
     *
     * Returns null if ANY item is malformed, rather than silently dropping it.
     * A product we cannot parse means the delivery is not what we think it is,
     * and quietly shrinking the list would hide that from the customer and from
     * the assistant_part/result.products agreement check.
     */
    public static function boundStrict(array $products): ?array
    {
        if (count($products) > self::MAX_PRODUCTS) {
            return null;   // the engine is contractually bounded; over-cap is a fault
        }

        $out = [];
        foreach ($products as $p) {
            if (! is_array($p)) {
                return null;
            }

            // EXACT key set, both directions: no extras, and nothing missing.
            $keys = array_keys($p);
            sort($keys);
            $expected = self::KEYS;
            sort($expected);
            if ($keys !== $expected) {
                return null;
            }

            $url = self::httpsUrl($p['url']);
            $title = self::str($p['title'], self::MAX_TITLE);
            $store = self::str($p['store'], self::MAX_STORE);
            $storeId = is_string($p['store_id']) ? trim($p['store_id']) : null;
            if ($url === null || $title === null || $store === null || $storeId === null) {
                return null;
            }
            if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $storeId)) {
                return null;
            }

            // Required, not nullable: "we don't know" is spelled `unknown`, and
            // spelling it null instead loses the difference between a stock
            // state the engine could not read and one it never looked at.
            if (! in_array($p['availability'], self::AVAILABILITY, true)) {
                return null;
            }

            $observedAt = self::utcRfc3339($p['observed_at']);
            if ($observedAt === null) {
                return null;
            }

            // Null is allowed; the KEY is not optional. A non-null image must be
            // a usable https url on the same terms as `url`.
            $image = $p['image'] === null ? null : self::httpsUrl($p['image']);
            if ($p['image'] !== null && $image === null) {
                return null;
            }

            $current = self::moneyOrFalse($p['current_price']);
            $list = self::moneyOrFalse($p['list_price']);
            if ($current === false || $list === false) {
                return null;
            }

            // Rebuilt from validated scalars in the frozen key order — never the
            // caller's array, so no attacker-owned reference survives the
            // boundary and the persisted order is stable across producers.
            $out[] = [
                'store'         => $store,
                'store_id'      => $storeId,
                'title'         => $title,
                'url'           => $url,
                'image'         => $image,
                'current_price' => $current,
                'list_price'    => $list,
                'availability'  => $p['availability'],
                'observed_at'   => $observedAt,
            ];
        }

        return $out;
    }

    /**
     * Read-path money extraction. Returns
     * ['price' => ?string, 'was' => ?string, 'on_sale' => bool] as DISPLAY values.
     *
     * USD only, per field. We do not convert currencies in a display adapter,
     * and showing an MXN number in a USD-shaped rail would be a lie the customer
     * acts on. This is presentation metadata: never a checkout price, never a
     * landed cost, never an input to a quote or charge.
     */
    public static function money(array $p): array
    {
        $current = self::usdAmount($p['current_price'] ?? null);
        $list    = self::usdAmount($p['list_price'] ?? null);

        return [
            'price'   => $current === null ? null : self::formatAmount($current),
            'was'     => $list === null ? null : self::formatAmount($list),
            // Only when BOTH sides are valid USD and the list is genuinely
            // higher. Equal prices are not a sale, and a currency mismatch is
            // not comparable — a fabricated badge is a claim about money.
            'on_sale' => $current !== null && $list !== null && $list > $current,
        ];
    }

    /** A finite, non-negative amount, but only when the currency is exactly USD. */
    private static function usdAmount($money): ?float
    {
        if (! is_array($money)) {
            return null;
        }

        $currency = $money['currency'] ?? null;
        if (! is_string($currency) || strtoupper(trim($currency)) !== 'USD') {
            return null;
        }

        $amount = $money['amount'] ?? null;
        if (! is_int($amount) && ! is_float($amount)) {
            return null;   // strings are NOT coerced; see moneyOrFalse
        }

        $amount = (float) $amount;

        return (is_finite($amount) && $amount >= 0) ? $amount : null;
    }

    private static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * null (absent) | array (valid money) | false (malformed).
     *
     * Numeric STRINGS are rejected rather than coerced: "12.50" and 12.50 are
     * different contracts, and silently accepting both means we never find out
     * which one the engine actually sends.
     */
    private static function moneyOrFalse($money)
    {
        if ($money === null) {
            return null;
        }
        if (! is_array($money) || array_diff(array_keys($money), ['amount', 'currency']) !== []) {
            return false;
        }

        $amount = $money['amount'] ?? null;
        if (! is_int($amount) && ! is_float($amount)) {
            return false;
        }
        $amount = (float) $amount;
        if (! is_finite($amount) || $amount < 0) {
            return false;
        }

        // ALREADY uppercase — not lowercased-then-normalized. At a frozen
        // boundary, accepting "usd" and storing "USD" means the three repos can
        // disagree about the wire format indefinitely without anyone noticing.
        // (money(), on the READ path, stays lenient: it parses rows that were
        // persisted before this freeze.)
        $currency = $money['currency'] ?? null;
        if (! is_string($currency) || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            return false;   // ISO 4217 shape
        }

        return ['amount' => $amount, 'currency' => $currency];
    }

    private static function str($value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            return null;
        }
        // Control characters have no place in a title rendered to a customer.
        return preg_match('/[\x00-\x1F\x7F]/u', $value) ? null : $value;
    }

    /**
     * A real https URL — PARSED, not prefix-matched.
     *
     * `str_starts_with($u, 'https://')` accepts `https://user:pass@evil/` and
     * `https://host/\npath`, which is why prefix checking is not enough here.
     */
    private static function httpsUrl($value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_URL) {
            return null;
        }
        // Checked BEFORE any trimming: trim() would silently repair
        // "https://x.test/1\n" into a valid URL, which hides that the engine
        // sent something malformed. Reject it instead of normalizing it.
        if (preg_match('/[\x00-\x1F\x7F\s]/', $value)) {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false
            || ($parts['scheme'] ?? '') !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            // A fragment is never meaningful to a fetcher — it is client-side
            // only — so its presence means the URL is not what it claims to be.
            // Query IS allowed: CDN image URLs legitimately carry one.
            || isset($parts['fragment'])) {
            return null;
        }

        // A host must look like a host: no empty labels, no bare punctuation.
        if (! preg_match('/^[A-Za-z0-9]([A-Za-z0-9\-.]*[A-Za-z0-9])?$/', $parts['host'])) {
            return null;
        }

        return $value;
    }

    /**
     * Strict UTC RFC3339: `YYYY-MM-DDTHH:MM:SS[.mmm]Z`, and nothing else.
     *
     * `strtotime()` alone is far too generous here — it accepts "now", "+1 day"
     * and a dozen offset spellings, so two producers could both be "valid"
     * while writing different formats. The pattern pins the wire format.
     *
     * The pattern is not sufficient on its own either: it happily matches
     * `2026-02-30T25:99:99Z`. And `strtotime()` does NOT reject that — it
     * silently rolls Feb 30 over to March 2, which would turn a malformed
     * timestamp into a plausible-looking fact. So the calendar and clock
     * components are checked explicitly, and the epoch comes from gmmktime.
     */
    private static function utcRfc3339($value): ?string
    {
        if (! is_string($value)
            || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,3})?Z$/', $value, $m)) {
            return null;
        }

        [, $year, $month, $day, $hour, $minute, $second] = array_map('intval', $m);

        if (! checkdate($month, $day, $year)) {
            return null;   // Feb 30 and friends
        }
        // No leap seconds: 60 is not a second we can represent downstream.
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        // An observation from the future is a broken clock or a fabricated
        // timestamp; either way it is not something to record as fact.
        if (gmmktime($hour, $minute, $second, $month, $day, $year) > time() + self::MAX_FUTURE_SECONDS) {
            return null;
        }

        return $value;
    }
}
