<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use HasFactory;

    const STATUS_UNKNOWN      = 'unknown';
    const STATUS_IN_STOCK     = 'in_stock';
    const STATUS_OUT_OF_STOCK = 'out_of_stock';

    protected $fillable = [
        'product_id',
        'size',
        'color',
        'shopify_variant_id',
        'price_cents',
        'stock_check_status',
        'last_stock_check_at',
        'last_stock_check_response',
        'display_order',
    ];

    protected $casts = [
        'price_cents'         => 'integer',
        'display_order'       => 'integer',
        'last_stock_check_at' => 'datetime',
    ];

    protected $appends = ['title'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Human-readable variant title — "M / Olive Wash", "Black", "L", etc.
     */
    public function getTitleAttribute(): string
    {
        $parts = array_filter([$this->size, $this->color]);
        return implode(' / ', $parts) ?: 'Default';
    }

    public function isAvailable(): bool
    {
        return $this->stock_check_status !== self::STATUS_OUT_OF_STOCK;
    }

    public function scopeInStock($query)
    {
        return $query->where('stock_check_status', '!=', self::STATUS_OUT_OF_STOCK);
    }
}
