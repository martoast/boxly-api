<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplaceOrder extends Model
{
    use HasFactory;

    const STATUS_COLLECTING = 'collecting';
    const STATUS_READY_TO_SHIP = 'ready_to_ship';
    const STATUS_PACKING = 'packing';
    const STATUS_AWAITING_SHIPPING_PAYMENT = 'awaiting_shipping_payment';
    const STATUS_SHIPPING_PAID = 'shipping_paid';
    const STATUS_SHIPPED = 'shipped';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'order_number',
        'user_id',
        'status',
        'items_subtotal_cents',
        'items_paid_at',
        'box_size',
        'box_price_cents',
        'box_summary',
        'shipping_invoice_id',
        'shipping_payment_link',
        'shipping_paid_at',
        'shipping_address',
        'guia_number',
        'gia_path',
        'gia_url',
        'estimated_delivery_date',
        'actual_delivery_date',
        'shipped_at',
        'shipment_requested_at',
        'refunded_at',
        'refund_amount_cents',
        'refund_reason',
    ];

    protected $casts = [
        'items_subtotal_cents'    => 'integer',
        'items_paid_at'           => 'datetime',
        'box_price_cents'         => 'integer',
        'box_summary'             => 'array',
        'shipping_paid_at'        => 'datetime',
        'shipping_address'        => 'array',
        'estimated_delivery_date' => 'date',
        'actual_delivery_date'    => 'date',
        'shipped_at'              => 'datetime',
        'shipment_requested_at'   => 'datetime',
        'refunded_at'             => 'datetime',
        'refund_amount_cents'     => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $order->order_number = static::generateOrderNumber();
            }
        });
    }

    public static function generateOrderNumber(): string
    {
        do {
            $number = 'MKT-' . strtoupper(Str::random(6));
        } while (static::where('order_number', $number)->exists());
        return $number;
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketplaceOrderItem::class);
    }

    // Scopes
    public function scopeCollecting($query)
    {
        return $query->where('status', self::STATUS_COLLECTING);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    // Helpers
    public function totalWeightKg(): float
    {
        return (float) $this->items->sum(function ($item) {
            return $item->weight_kg_snapshot * $item->quantity;
        });
    }

    public function isPaid(): bool
    {
        return ! is_null($this->items_paid_at);
    }

    public function canRequestShipment(): bool
    {
        if ($this->status !== self::STATUS_COLLECTING) return false;
        // All items must be paid
        return ! $this->items()->where('status', 'pending_payment')->exists()
            && $this->items()->count() > 0;
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [
            self::STATUS_COLLECTING,
            self::STATUS_READY_TO_SHIP,
        ]);
    }
}
