<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'size',
        'color',
        'shopify_variant_id',
        'price_cents',
        'display_order',
    ];

    protected $casts = [
        'price_cents'   => 'integer',
        'display_order' => 'integer',
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
}
