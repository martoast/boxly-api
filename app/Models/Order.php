<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_name',
        'order_number',
        'status',
        'total_weight',
        'recommended_box_size',
        'stripe_invoice_id',
        'stripe_invoice_url',
        'amount_paid',
        'currency',
        'stripe_payment_intent_id',
        'tracking_number',
        'delivery_address',
        'is_rural',
        'estimated_delivery_date',
        'actual_delivery_date',
        'completed_at',
        'quote_sent_at',
        'paid_at',
        'shipped_at',
        'delivered_at',
    ];

    protected $casts = [
        'delivery_address' => 'array',
        'is_rural' => 'boolean',
        'total_weight' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'estimated_delivery_date' => 'date',
        'actual_delivery_date' => 'date',
        'completed_at' => 'datetime',
        'quote_sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    const STATUS_COLLECTING = 'collecting';
    const STATUS_AWAITING_PACKAGES = 'awaiting_packages';
    const STATUS_PACKAGES_COMPLETE = 'packages_complete';
    const STATUS_QUOTE_SENT = 'quote_sent';
    const STATUS_PAID = 'paid';
    const STATUS_SHIPPED = 'shipped';
    const STATUS_DELIVERED = 'delivered';

    /**
     * Box size constants with prices in MXN
     */
    const BOX_SIZES = [
        'small' => ['max_weight' => 10, 'price' => 2200],
        'medium' => ['max_weight' => 25, 'price' => 3800],
        'large' => ['max_weight' => 40, 'price' => 5500],
        'xl' => ['max_weight' => 60, 'price' => 7000],
    ];

    const RURAL_SURCHARGE = 400;

    /**
     * Get all available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_COLLECTING => 'Adding Items',
            self::STATUS_AWAITING_PACKAGES => 'Awaiting Packages',
            self::STATUS_PACKAGES_COMPLETE => 'Packages Complete',
            self::STATUS_QUOTE_SENT => 'Quote Sent',
            self::STATUS_PAID => 'Paid',
            self::STATUS_SHIPPED => 'Shipped',
            self::STATUS_DELIVERED => 'Delivered',
        ];
    }

    /**
     * Get the user that owns the order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the order items.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get items that have arrived
     */
    public function arrivedItems(): HasMany
    {
        return $this->items()->where('arrived', true);
    }

    /**
     * Get items that haven't arrived
     */
    public function pendingItems(): HasMany
    {
        return $this->items()->where('arrived', false);
    }

    /**
     * Check if all items have arrived
     */
    public function allItemsArrived(): bool
    {
        if ($this->items()->count() === 0) {
            return false;
        }
        
        return $this->items()->where('arrived', false)->count() === 0;
    }

    /**
     * Get arrival progress percentage
     */
    public function getArrivalProgressAttribute(): int
    {
        $total = $this->items()->count();
        if ($total === 0) return 0;
        
        $arrived = $this->arrivedItems()->count();
        return round(($arrived / $total) * 100);
    }

    /**
     * Check if order can be quoted (all items arrived and weighed)
     */
    public function canBeQuoted(): bool
    {
        return $this->status === self::STATUS_PACKAGES_COMPLETE && 
               $this->allItemsArrived() &&
               $this->items()->whereNull('weight')->count() === 0;
    }

    /**
     * Calculate total weight from items
     */
    public function calculateTotalWeight(): float
    {
        return $this->items()->sum('weight') ?: 0;
    }

    /**
     * Determine box size based on weight
     */
    public function determineBoxSize(): ?string
    {
        $weight = $this->calculateTotalWeight();
        
        foreach (self::BOX_SIZES as $size => $config) {
            if ($weight <= $config['max_weight']) {
                return $size;
            }
        }
        
        return null; // Too heavy
    }

    /**
     * Calculate shipping cost
     */
    public function calculateShippingCost(): int
    {
        $boxSize = $this->recommended_box_size ?: $this->determineBoxSize();
        
        if (!$boxSize || !isset(self::BOX_SIZES[$boxSize])) {
            return 0;
        }
        
        $cost = self::BOX_SIZES[$boxSize]['price'];
        
        if ($this->is_rural) {
            $cost += self::RURAL_SURCHARGE;
        }
        
        return $cost;
    }

    /**
     * Calculate IVA amount
     */
    public function calculateIvaAmount(): float
    {
        $declaredTotal = $this->items->sum(function ($item) {
            return $item->declared_value * $item->quantity;
        });
        return round($declaredTotal * 0.16, 2);
    }

    /**
     * Calculate total amount (shipping + IVA)
     */
    public function calculateTotalAmount(): float
    {
        return $this->calculateShippingCost() + $this->calculateIvaAmount();
    }

    /**
     * Mark order as complete (ready for consolidation)
     */
    public function markAsComplete(): void
    {
        if ($this->status !== self::STATUS_COLLECTING) {
            throw new \Exception('Order can only be completed from collecting status');
        }
        
        if ($this->items()->count() === 0) {
            throw new \Exception('Order must have at least one item');
        }
        
        $this->update([
            'status' => self::STATUS_AWAITING_PACKAGES,
            'completed_at' => now(),
        ]);
    }

    /**
     * Update status when all packages arrive
     */
    public function checkAndUpdatePackageStatus(): void
    {
        if ($this->status === self::STATUS_AWAITING_PACKAGES && $this->allItemsArrived()) {
            $this->update([
                'status' => self::STATUS_PACKAGES_COMPLETE,
                'total_weight' => $this->calculateTotalWeight(),
            ]);
        }
    }

    /**
     * Generate next order number
     */
    public static function generateOrderNumber(): string
    {
        $year = date('Y');
        $count = self::whereYear('created_at', $year)->count() + 1;
        return sprintf('PC-%s-%06d', $year, $count);
    }

    /**
     * Scope for filtering by status
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for user's orders
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for orders ready to quote
     */
    public function scopeReadyToQuote($query)
    {
        return $query->where('status', self::STATUS_PACKAGES_COMPLETE);
    }

    /**
     * Scope for unpaid orders
     */
    public function scopeUnpaid($query)
    {
        return $query->where('status', self::STATUS_QUOTE_SENT);
    }
}