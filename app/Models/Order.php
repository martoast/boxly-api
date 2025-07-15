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
        'order_number',
        'status',
        'box_size',
        'box_price',
        'declared_value',
        'iva_amount',
        'is_rural',
        'rural_surcharge',
        'total_weight',
        'stripe_product_id',
        'stripe_price_id',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'amount_paid',
        'currency',
        'paid_at',
        'tracking_number',
        'delivery_address',
        'estimated_delivery_date',
        'actual_delivery_date',
        'completed_at',
        'shipped_at',
        'delivered_at',
    ];

    protected $casts = [
        'delivery_address' => 'array',
        'is_rural' => 'boolean',
        'box_price' => 'decimal:2',
        'rural_surcharge' => 'decimal:2',
        'total_weight' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'paid_at' => 'datetime',
        'estimated_delivery_date' => 'date',
        'actual_delivery_date' => 'date',
        'completed_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    const STATUS_COLLECTING = 'collecting';
    const STATUS_AWAITING_PACKAGES = 'awaiting_packages';
    const STATUS_PACKAGES_COMPLETE = 'packages_complete';
    const STATUS_SHIPPED = 'shipped';
    const STATUS_DELIVERED = 'delivered';

    /**
     * Get all available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_COLLECTING => 'Adding Items',
            self::STATUS_AWAITING_PACKAGES => 'Awaiting Packages',
            self::STATUS_PACKAGES_COMPLETE => 'Packages Complete',
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
                'total_weight' => $this->items()->sum('weight'),
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
}