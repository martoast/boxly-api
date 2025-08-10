<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Mail;
use App\Mail\OrderStatusChanged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_number',
        'tracking_number',
        'status',
        'box_size',
        'box_price',
        'declared_value',
        'iva_amount',
        'is_rural',
        'rural_surcharge',
        'total_weight',
        'actual_weight',
        'shipping_cost',
        'handling_fee',
        'insurance_fee',
        'stripe_product_id',
        'stripe_price_id',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'stripe_invoice_id',
        'payment_link',
        'amount_paid',
        'quoted_amount',
        'quote_breakdown',
        'currency',
        'paid_at',
        'delivery_address',
        'estimated_delivery_date',
        'actual_delivery_date',
        'completed_at',
        'processing_started_at',
        'quote_sent_at',
        'quote_expires_at',
        'shipped_at',
        'delivered_at',
        'notes',
    ];

    protected $casts = [
        'delivery_address' => 'array',
        'quote_breakdown' => 'array',
        'is_rural' => 'boolean',
        'box_price' => 'decimal:2',
        'rural_surcharge' => 'decimal:2',
        'total_weight' => 'decimal:2',
        'actual_weight' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'handling_fee' => 'decimal:2',
        'insurance_fee' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'quoted_amount' => 'decimal:2',
        'declared_value' => 'decimal:2',
        'iva_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'estimated_delivery_date' => 'date',
        'actual_delivery_date' => 'date',
        'completed_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'quote_sent_at' => 'datetime',
        'quote_expires_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    const STATUS_COLLECTING = 'collecting'; // User is adding items
    const STATUS_AWAITING_PACKAGES = 'awaiting_packages'; // Items added, waiting for packages
    const STATUS_PACKAGES_COMPLETE = 'packages_complete'; // All packages arrived
    const STATUS_PROCESSING = 'processing'; // Admin is processing the order
    const STATUS_QUOTE_SENT = 'quote_sent'; // Quote has been sent to customer
    const STATUS_PAID = 'paid'; // Customer has paid
    const STATUS_SHIPPED = 'shipped'; // Order has been shipped
    const STATUS_DELIVERED = 'delivered'; // Order has been delivered
    const STATUS_CANCELLED = 'cancelled'; // Order was cancelled

    /**
     * Temporary property to store previous status
     */
    public $previousStatus;

    /**
     * Boot method for the model
     */
    protected static function boot()
    {
        parent::boot();

        // Watch for status changes
        static::updating(function ($order) {
            if ($order->isDirty('status')) {
                $order->previousStatus = $order->getOriginal('status');
            }
        });

        static::updated(function ($order) {
            // Send email when status changes
            if ($order->isDirty('status') && isset($order->previousStatus)) {
                $order->load('user');

                Log::info('Order status changed', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'previous_status' => $order->previousStatus,
                    'new_status' => $order->status,
                ]);

                try {
                    // Define transitions that should NOT send email
                    $skipEmail = false;

                    // Skip when transitioning TO quote_sent (Stripe handles it)
                    if ($order->status === self::STATUS_QUOTE_SENT) {
                        Log::info('Quote sent - Stripe will handle the invoice email');
                        $skipEmail = true;
                    }

                    // Skip when transitioning from awaiting_packages to processing
                    elseif (
                        $order->previousStatus === self::STATUS_AWAITING_PACKAGES &&
                        $order->status === self::STATUS_PROCESSING
                    ) {
                        Log::info('Skipping email for awaiting_packages to processing transition');
                        $skipEmail = true;
                    }

                    // Skip when transitioning from packages_complete to processing
                    elseif (
                        $order->previousStatus === self::STATUS_PACKAGES_COMPLETE &&
                        $order->status === self::STATUS_PROCESSING
                    ) {
                        Log::info('Skipping email for packages_complete to processing transition');
                        $skipEmail = true;
                    }

                    // Send email only if not skipped
                    if (!$skipEmail) {
                        Mail::to($order->user)->send(new OrderStatusChanged($order, $order->previousStatus));
                        Log::info('Order status change email sent successfully');
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to send order status email', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        });
    }

    /**
     * Get all available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_COLLECTING => 'Collecting Items',
            self::STATUS_AWAITING_PACKAGES => 'Awaiting Packages',
            self::STATUS_PACKAGES_COMPLETE => 'Packages Complete',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_QUOTE_SENT => 'Quote Sent',
            self::STATUS_PAID => 'Paid',
            self::STATUS_SHIPPED => 'Shipped',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_CANCELLED => 'Cancelled',
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
     * Check if all items have been weighed
     */
    public function allItemsWeighed(): bool
    {
        if ($this->items()->count() === 0) {
            return false;
        }

        return $this->items()->whereNull('weight')->count() === 0;
    }

    /**
     * Check if order can be quoted
     */
    public function canBeQuoted(): bool
    {
        return $this->status === self::STATUS_PACKAGES_COMPLETE &&
            $this->allItemsArrived() &&
            $this->allItemsWeighed();
    }

    /**
     * Check if order can be processed (all items arrived and weighed)
     */
    public function canBeProcessed(): bool
    {
        return $this->status === self::STATUS_PACKAGES_COMPLETE &&
            $this->allItemsArrived() &&
            $this->allItemsWeighed();
    }

    /**
     * Check if order is ready for quote
     */
    public function isReadyForQuote(): bool
    {
        return $this->status === self::STATUS_PROCESSING &&
            $this->actual_weight !== null &&
            $this->shipping_cost !== null;
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
     * Reopen order for adding more items
     */
    public function reopenForEditing(): void
    {
        if ($this->status !== self::STATUS_AWAITING_PACKAGES) {
            throw new \Exception('Order can only be reopened from awaiting packages status');
        }

        // Don't allow reopening if packages have started arriving
        if ($this->arrivedItems()->count() > 0) {
            throw new \Exception('Cannot reopen order - some packages have already arrived');
        }

        $this->update([
            'status' => self::STATUS_COLLECTING,
            'completed_at' => null,
        ]);
    }

    /**
     * Mark order as processing
     */
    public function markAsProcessing(): void
    {
        if ($this->status !== self::STATUS_PACKAGES_COMPLETE) {
            throw new \Exception('Order can only be marked as processing when all packages are complete');
        }

        $this->update([
            'status' => self::STATUS_PROCESSING,
            'processing_started_at' => now(),
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
                'completed_at' => now(),
            ]);
        }
    }

    /**
     * Calculate the total quote amount
     */
    public function calculateQuoteAmount(): float
    {
        $breakdown = $this->quote_breakdown ?? [];
        $total = 0;

        foreach ($breakdown as $item) {
            if (isset($item['amount'])) {
                $total += floatval($item['amount']);
            }
        }

        return $total;
    }

    /**
     * Check if order has been paid
     */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID ||
            $this->status === self::STATUS_SHIPPED ||
            $this->status === self::STATUS_DELIVERED;
    }

    /**
     * Check if quote has expired
     */
    public function isQuoteExpired(): bool
    {
        if (!$this->quote_expires_at) {
            return false;
        }

        return $this->quote_expires_at->isPast();
    }

    /**
     * Generate next order number
     */
    public static function generateOrderNumber(): string
    {
        $year = date('y'); // last 2 digits of year
        $random = strtoupper(Str::random(6));
        return $year . $random; // e.g., 25A9B2X7
    }

    /**
     * Generate a unique tracking number
     */
    public static function generateTrackingNumber(): string
    {
        do {
            // Prefix to indicate it's a tracking number
            $tracking = 'TRK' . strtoupper(Str::random(6)); // e.g., TRK4F9B2X
        } while (self::where('tracking_number', $tracking)->exists());

        return $tracking;
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
     * Scope for unpaid orders with quotes
     */
    public function scopeUnpaidWithQuotes($query)
    {
        return $query->where('status', self::STATUS_QUOTE_SENT)
            ->whereNotNull('payment_link');
    }

    /**
     * Scope for orders awaiting payment
     */
    public function scopeAwaitingPayment($query)
    {
        return $query->where('status', self::STATUS_QUOTE_SENT)
            ->whereNotNull('payment_link');
    }

    /**
     * Scope for orders ready to process
     */
    public function scopeReadyToProcess($query)
    {
        return $query->where('status', self::STATUS_PACKAGES_COMPLETE);
    }

    /**
     * Scope for collecting orders
     */
    public function scopeCollecting($query)
    {
        return $query->where('status', self::STATUS_COLLECTING);
    }

    /**
     * Calculate total weight from all items
     */
    public function calculateTotalWeight(): ?float
    {
        $totalWeight = $this->items()->whereNotNull('weight')->sum('weight');
        return $totalWeight > 0 ? $totalWeight : null;
    }

    /**
     * Calculate total declared value from all items
     */
    public function calculateTotalDeclaredValue(): float
    {
        return $this->items()->sum('declared_value') ?? 0;
    }

    /**
     * Calculate IVA based on total declared value
     */
    public function calculateIVA(): float
    {
        $totalDeclaredValue = $this->calculateTotalDeclaredValue();

        // IVA only applies when declared value is $50 USD or more
        if ($totalDeclaredValue >= 50) {
            return round($totalDeclaredValue * 0.16, 2);
        }

        return 0;
    }

    /**
     * Convert USD to MXN using configured exchange rate
     */
    public function convertToMXN($usdAmount): float
    {
        $exchangeRate = config('services.exchange_rate.usd_to_mxn', 18.00);
        return round($usdAmount * $exchangeRate, 2);
    }
}
