<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceOrderItem extends Model
{
    use HasFactory;

    const STATUS_PENDING_PAYMENT = 'pending_payment';
    const STATUS_ORDERED = 'ordered';
    const STATUS_RECEIVED = 'received';
    const STATUS_PACKED = 'packed';
    const STATUS_SHIPPED = 'shipped';

    protected $fillable = [
        'marketplace_order_id',
        'product_id',
        'name_snapshot',
        'price_cents_snapshot',
        'weight_kg_snapshot',
        'image_url_snapshot',
        'quantity',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'paid_at',
        'status',
        'received_at',
        // Source purchase tracking (admin records after buying from US store)
        'source_purchased_at',
        'source_order_id',
        'source_tracking_number',
        'source_carrier',
    ];

    protected $casts = [
        'price_cents_snapshot' => 'integer',
        'weight_kg_snapshot'   => 'decimal:2',
        'quantity'             => 'integer',
        'paid_at'              => 'datetime',
        'received_at'          => 'datetime',
        'source_purchased_at'  => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lineTotalCents(): int
    {
        return $this->price_cents_snapshot * $this->quantity;
    }

    public function lineWeightKg(): float
    {
        return (float) $this->weight_kg_snapshot * $this->quantity;
    }
}
