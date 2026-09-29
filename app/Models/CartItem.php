<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    public const MAX_QUANTITY = 20;

    public const SOURCES = ['chat', 'live', 'extension'];

    protected $fillable = [
        'cart_id', 'store_id', 'store_name', 'product_url', 'product_url_hash', 'title',
        'image_url', 'price', 'currency', 'quantity', 'variants', 'variants_key',
        'source', 'saved_id', 'sync_status', 'sync_note', 'find_query',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'variants' => 'array',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * The product's name as a shopper reads it (Lab 2026-09-28: "Carhartt Women's Cuffed Rib Knit Beanie (105560)"
     * on the order and the invoice): a trailing product code in brackets ("(105560)", "[SKU 12-AB3]", "Style #K87")
     * and a trailing "| Store" page-title suffix are dropped. A name that would be left empty is kept as it was.
     */
    public static function cleanTitle(string $title): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $title));
        $before = null;
        while ($before !== $clean) {
            $before = $clean;
            $clean = preg_replace_callback('/\s*[(\[]\s*((?:sku|style|item|model|ref|art)\.?\s*(?:no\.?|#)?\s*:?\s*)?#?\s*((?=[A-Z0-9-]*\d)[A-Z0-9][A-Z0-9-]{3,})\s*[)\]]$/iu', function ($m) {
                // A size or a model year is part of the name ("(128GB)", "(24oz)", "iPad (2022)"), unless labelled a code.
                $unit = preg_match('/^\d+(?:\.\d+)?(?:gb|tb|mb|oz|ml|mm|cm|in|pk|ct|lbs?|kg|g|w|v|mah|hz|p|k|pcs?)$/i', $m[2]);
                $year = preg_match('/^(?:19|20)\d\d$/', $m[2]);

                return ($unit || $year) && trim((string) $m[1]) === '' ? $m[0] : '';
            }, $clean);
            $clean = preg_replace('/\s*[-–,]?\s*(?:sku|style|item|model)\s*(?:no\.?|#|:)\s*(?=[A-Z0-9-]*\d)[A-Z0-9-]{3,}$/iu', '', $clean);
            $clean = preg_replace('/\s+\|\s+[^|]{1,40}$/u', '', $clean);
            $clean = trim($clean);
        }

        return $clean !== '' ? $clean : trim($title);
    }

    /**
     * The dedupe key for a variant selection: lowercase, trimmed, sorted by key,
     * "k=v|k=v". {"Size":"M","Color":"Red"} and {"color":"red","size":"m"} are
     * the same line in the cart. Nothing selected is the empty string.
     */
    public static function variantsKey(array $variants): string
    {
        $norm = [];
        foreach ($variants as $k => $v) {
            $norm[mb_strtolower(trim((string) $k))] = mb_strtolower(trim((string) $v));
        }
        ksort($norm, SORT_STRING);

        $key = implode('|', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($norm), $norm));

        // Six long options can exceed the 255-char column; truncating could make
        // two different selections collide, so an oversized key is hashed whole.
        return strlen($key) <= 255 ? $key : 'sha256:' . hash('sha256', $key);
    }

    public static function urlHash(string $url): string
    {
        return hash('sha256', $url);
    }
}
