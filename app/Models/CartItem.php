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
        'source', 'saved_id', 'sync_status', 'sync_note',
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
