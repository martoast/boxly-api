<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderBox extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'stripe_price_id',
        'stripe_product_id',
        'box_size',
        'box_name',
        'box_price',
        'currency',
        'quantity',
    ];

    protected $casts = [
        'box_price' => 'decimal:2',
        'quantity' => 'integer',
    ];

    /**
     * Get the order that owns this box.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the total price for this box entry (price * quantity).
     */
    public function getTotalPriceAttribute(): float
    {
        return (float) $this->box_price * $this->quantity;
    }

    /**
     * Get a formatted description of this box.
     */
    public function getDescriptionAttribute(): string
    {
        if ($this->quantity > 1) {
            return "{$this->quantity}x {$this->box_name}";
        }
        return $this->box_name;
    }
}
