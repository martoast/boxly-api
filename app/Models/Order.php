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
use Illuminate\Support\Collection;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_number',
        'tracking_number',
        'status',
        'order_type',
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
        'gia_path',
        'gia_filename',
        'gia_mime_type',
        'gia_size',
        'gia_url',
        'guia_number',
        'deposit_amount', // New
        'deposit_paid_at', // New
        'deposit_invoice_id', // New
        'deposit_payment_link', // New
        'consolidation_invoice_id',
        'consolidation_payment_link',
        'consolidation_paid_at',
        'arrival_image_path',
        'arrival_image_filename',
        'arrival_image_mime_type',
        'arrival_image_size',
        'arrival_image_url',
        'proof_of_purchase_files',
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
        'deposit_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'deposit_paid_at' => 'datetime',
        'consolidation_paid_at' => 'datetime',
        'estimated_delivery_date' => 'date',
        'actual_delivery_date' => 'date',
        'completed_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'quote_sent_at' => 'datetime',
        'quote_expires_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'gia_size' => 'integer',
        'arrival_image_size' => 'integer',
        'proof_of_purchase_files' => 'array',
    ];

    protected $appends = [
        'total_box_weight',
    ];

    // Updated status constants
    const STATUS_COLLECTING = 'collecting';
    const STATUS_AWAITING_PACKAGES = 'awaiting_packages';
    const STATUS_PACKAGES_COMPLETE = 'packages_complete';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SHIPPED = 'shipped';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    const STATUS_PAID = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    public $previousStatus;
    public $skipEmailNotifications = false;

    protected static function boot()
    {
        parent::boot();

        static::updating(function ($order) {
            if ($order->isDirty('status')) {
                $order->previousStatus = $order->getOriginal('status');
            }
        });

        static::updated(function ($order) {
            if ($order->skipEmailNotifications) {
                Log::info('Email notification skipped for admin manual operation', [
                    'order_id' => $order->id,
                    'new_status' => $order->status,
                ]);
                return;
            }

            if ($order->isDirty('status') && isset($order->previousStatus)) {
                // Skip email for PACKAGES_COMPLETE - the consolidation step sends its own email with invoice
                if ($order->status === self::STATUS_PACKAGES_COMPLETE) {
                    Log::info('Skipping packages_complete email - consolidation will send invoice email', [
                        'order_id' => $order->id,
                    ]);
                    return;
                }

                // Skip email for PAID - PaymentReceived email is sent separately
                if ($order->status === self::STATUS_PAID) {
                    Log::info('Skipping paid status email - PaymentReceived email handles this', [
                        'order_id' => $order->id,
                    ]);
                    return;
                }

                $order->load('user', 'items');

                Log::info('Order status changed', [
                    'order_id' => $order->id,
                    'previous_status' => $order->previousStatus,
                    'new_status' => $order->status,
                ]);

                try {
                    Mail::to($order->user)->send(new OrderStatusChanged($order, $order->previousStatus));
                } catch (\Exception $e) {
                    Log::error('Failed to send order status email', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        });
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_COLLECTING => 'Collecting Items',
            self::STATUS_AWAITING_PACKAGES => 'Awaiting Packages',
            self::STATUS_PACKAGES_COMPLETE => 'Packages Complete',
            self::STATUS_AWAITING_PAYMENT => 'Awaiting Payment',
            self::STATUS_PAID => 'Paid',
            self::STATUS_PROCESSING => 'Processing (Legacy)',
            self::STATUS_SHIPPED => 'Shipped',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function arrivedItems(): HasMany
    {
        return $this->items()->where('arrived', true);
    }

    public function pendingItems(): HasMany
    {
        return $this->items()->where('arrived', false);
    }

    /**
     * Get the boxes associated with this order.
     * Supports multiple boxes per order.
     */
    public function boxes(): HasMany
    {
        return $this->hasMany(OrderBox::class);
    }

    public function allItemsArrived(): bool
    {
        if ($this->items()->count() === 0) return false;
        return $this->items()->where('arrived', false)->count() === 0;
    }

    public function allItemsWeighed(): bool
    {
        if ($this->items()->count() === 0) return false;
        return $this->items()->whereNull('weight')->count() === 0;
    }

    public function canBeQuoted(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    public function canBeProcessed(): bool
    {
        return $this->status === self::STATUS_PACKAGES_COMPLETE &&
            $this->allItemsArrived() &&
            $this->allItemsWeighed();
    }

    public function isReadyForQuote(): bool
    {
        return $this->status === self::STATUS_DELIVERED &&
            $this->actual_weight !== null;
    }

    public function getArrivalProgressAttribute(): int
    {
        $total = $this->items()->count();
        if ($total === 0) return 0;
        $arrived = $this->arrivedItems()->count();
        return round(($arrived / $total) * 100);
    }

    public function markAsComplete(): void
    {
        if ($this->status !== self::STATUS_COLLECTING) {
            throw new \Exception('Order can only be completed from collecting status');
        }

        if ($this->items()->count() === 0) {
            throw new \Exception('Order must have at least one item');
        }

        if (empty($this->proof_of_purchase_files)) {
            throw new \Exception('Order must have at least one proof of purchase');
        }

        $this->update([
            'status' => self::STATUS_AWAITING_PACKAGES,
            'completed_at' => now(),
        ]);

        // Note: packages_complete status is now triggered when admin uploads arrival image
        // Just update weight if all items are already arrived
        if ($this->allItemsArrived()) {
            $this->update([
                'total_weight' => $this->calculateTotalWeight(),
            ]);
        }
    }

    public function reopenForEditing(): void
    {
        if (!in_array($this->status, [self::STATUS_AWAITING_PACKAGES, self::STATUS_PACKAGES_COMPLETE])) {
            throw new \Exception('Order cannot be reopened in current status');
        }

        $this->update([
            'status' => self::STATUS_COLLECTING,
            'completed_at' => null,
        ]);
    }

    public function canBeReopened(): bool
    {
        return in_array($this->status, [
            self::STATUS_AWAITING_PACKAGES,
            self::STATUS_PACKAGES_COMPLETE
        ]);
    }

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

    public function checkAndUpdatePackageStatus(): void
    {
        // Note: packages_complete status is now triggered when admin uploads arrival image
        // This method only updates the total weight when all items arrive
        if ($this->status === self::STATUS_AWAITING_PACKAGES && $this->allItemsArrived()) {
            $this->update([
                'total_weight' => $this->calculateTotalWeight(),
            ]);
        }
    }

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

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isDepositPaid(): bool
    {
        return !is_null($this->deposit_paid_at);
    }

    public function isConsolidationPaid(): bool
    {
        // In the new flow, we use paid_at instead of consolidation_paid_at
        return !is_null($this->paid_at) || in_array($this->status, [
            self::STATUS_PAID,
            self::STATUS_SHIPPED,
            self::STATUS_DELIVERED,
        ]);
    }

    /**
     * Check if this is a crossing-only order (no shipping delivery).
     */
    public function isCrossingOnly(): bool
    {
        return $this->order_type === 'crossing';
    }

    /**
     * Check if this is a shipping order (includes delivery).
     * Treats null order_type as shipping for legacy compatibility.
     */
    public function isShipping(): bool
    {
        return $this->order_type === 'shipping' || $this->order_type === null;
    }

    /**
     * Check if order has multiple boxes.
     */
    public function hasMultipleBoxes(): bool
    {
        return $this->boxes()->count() > 1 || $this->boxes()->sum('quantity') > 1;
    }

    /**
     * Get total box count (sum of all quantities).
     */
    public function getTotalBoxCountAttribute(): int
    {
        return (int) $this->boxes()->sum('quantity');
    }

    /**
     * Calculate total price of all boxes.
     * This is the sum of (box_price * quantity) for each box entry.
     */
    public function calculateTotalBoxPrice(): float
    {
        // If using the new boxes relationship
        if ($this->boxes()->exists()) {
            return (float) $this->boxes->sum(function ($box) {
                return $box->box_price * $box->quantity;
            });
        }

        // Fallback to legacy single box_price field for backwards compatibility
        return (float) ($this->box_price ?? 0);
    }

    /**
     * Calculate the deposit amount (50% of total box price).
     */
    public function calculateDepositAmount(): float
    {
        return round($this->calculateTotalBoxPrice() * 0.5, 2);
    }

    /**
     * Get a summary of boxes for display.
     * Returns array like: ['1x Medium Box', '2x Large Box']
     */
    public function getBoxSummaryAttribute(): array
    {
        if (!$this->boxes()->exists()) {
            // Fallback to legacy single box
            if ($this->box_size) {
                return [ucfirst(str_replace('-', ' ', $this->box_size)) . ' Box'];
            }
            return [];
        }

        return $this->boxes->map(function ($box) {
            return $box->description;
        })->toArray();
    }

    /**
     * Get box distribution counts for this order.
     * Returns array like: ['medium' => 1, 'large' => 2]
     */
    public function getBoxDistribution(): array
    {
        $distribution = [
            'extra-small' => 0,
            'small' => 0,
            'medium' => 0,
            'large' => 0,
            'extra-large' => 0,
        ];

        if ($this->boxes()->exists()) {
            foreach ($this->boxes as $box) {
                if (isset($distribution[$box->box_size])) {
                    $distribution[$box->box_size] += $box->quantity;
                }
            }
        } elseif ($this->box_size && isset($distribution[$this->box_size])) {
            // Fallback to legacy single box
            $distribution[$this->box_size] = 1;
        }

        return $distribution;
    }

    public function isQuoteExpired(): bool
    {
        if (!$this->quote_expires_at) return false;
        return $this->quote_expires_at->isPast();
    }

    public static function generateOrderNumber(): string
    {
        $year = date('y');
        $random = strtoupper(Str::random(6));
        return $year . $random;
    }

    public static function generateTrackingNumber(): string
    {
        do {
            $tracking = 'TRK' . strtoupper(Str::random(6));
        } while (self::where('tracking_number', $tracking)->exists());
        return $tracking;
    }

    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeUnpaidWithQuotes($query)
    {
        return $query->where('status', self::STATUS_AWAITING_PAYMENT)
            ->whereNotNull('payment_link');
    }

    public function scopeReadyToProcess($query)
    {
        return $query->where('status', self::STATUS_PACKAGES_COMPLETE);
    }

    public function scopeCollecting($query)
    {
        return $query->where('status', self::STATUS_COLLECTING);
    }

    public function scopeReadyForInvoice($query)
    {
        return $query->where('status', self::STATUS_DELIVERED)
            ->whereNull('quote_sent_at');
    }

    public function calculateTotalWeight(): ?float
    {
        $totalWeight = $this->items()->whereNotNull('weight')->sum('weight');
        return $totalWeight > 0 ? $totalWeight : null;
    }

    /**
     * Calculate total weight from all boxes in the order.
     */
    public function calculateTotalBoxWeight(): ?float
    {
        $totalWeight = $this->boxes()->whereNotNull('weight')->sum('weight');
        return $totalWeight > 0 ? round($totalWeight, 2) : null;
    }

    /**
     * Get the total box weight attribute for API responses.
     */
    public function getTotalBoxWeightAttribute(): ?float
    {
        return $this->calculateTotalBoxWeight();
    }

    public function calculateTotalDeclaredValue(): float
    {
        return $this->items()->sum('declared_value') ?? 0;
    }

    public function calculateIVA(): float
    {
        $totalDeclaredValue = $this->calculateTotalDeclaredValue();
        if ($totalDeclaredValue >= 50) {
            return round($totalDeclaredValue * 0.16, 2);
        }
        return 0;
    }

    public function getGiaFullUrlAttribute(): ?string
    {
        if (!$this->gia_url) return null;
        if (filter_var($this->gia_url, FILTER_VALIDATE_URL)) return $this->gia_url;
        return config('filesystems.disks.spaces.url') . '/' . $this->gia_url;
    }

    public function getDhlTrackingUrlAttribute(): ?string
    {
        if (!$this->guia_number) return null;
        $cleanGuia = str_replace(' ', '', $this->guia_number);
        return "https://www.dhl.com/mx-es/home/tracking.html?tracking-id={$cleanGuia}";
    }

    public function deleteGia(): void
    {
        // Delete order-level GIA (legacy/backwards compatibility)
        if ($this->gia_path) {
            \Illuminate\Support\Facades\Storage::disk('spaces')->delete($this->gia_path);
            $this->update([
                'gia_path' => null,
                'gia_filename' => null,
                'gia_mime_type' => null,
                'gia_size' => null,
                'gia_url' => null,
            ]);
        }

        // Also delete all box-level GIAs
        $this->deleteAllBoxGias();
    }

    public function getFormattedGuiaAttribute(): ?string
    {
        if (!$this->guia_number) return null;
        $clean = str_replace(' ', '', $this->guia_number);
        if (strlen($clean) === 10) {
            return substr($clean, 0, 2) . ' ' . substr($clean, 2, 4) . ' ' . substr($clean, 6, 4);
        }
        return $this->guia_number;
    }

    /**
     * Get all boxes that have GIA files uploaded.
     */
    public function getBoxesWithGia()
    {
        return $this->boxes()->whereNotNull('gia_path')->get();
    }

    /**
     * Delete all GIA files from boxes.
     */
    public function deleteAllBoxGias(): void
    {
        foreach ($this->boxes as $box) {
            if ($box->hasGia()) {
                $box->deleteGia();
            }
        }
    }

    /**
     * Check if all boxes have GIA files (for shipping orders).
     */
    public function allBoxesHaveGia(): bool
    {
        if ($this->isCrossingOnly()) {
            return true; // Crossing orders don't need GIAs
        }

        $boxes = $this->boxes;
        if ($boxes->isEmpty()) {
            return false;
        }

        return $boxes->every(fn($box) => $box->hasGia());
    }

    /**
     * Get count of boxes with GIA files.
     */
    public function getBoxesWithGiaCountAttribute(): int
    {
        return $this->boxes->filter(fn($box) => $box->hasGia())->count();
    }

    public function arrivalImages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OrderArrivalImage::class);
    }

    /**
     * Check if the order has an arrival image uploaded.
     */
    public function hasArrivalImage(): bool
    {
        return !empty($this->arrival_image_path) || !empty($this->arrival_image_url);
    }

    /**
     * Get the full URL for the arrival image.
     */
    public function getArrivalImageFullUrlAttribute(): ?string
    {
        if (!$this->arrival_image_url && !$this->arrival_image_path) {
            return null;
        }

        if ($this->arrival_image_url && filter_var($this->arrival_image_url, FILTER_VALIDATE_URL)) {
            return $this->arrival_image_url;
        }

        if ($this->arrival_image_path) {
            return config('filesystems.disks.spaces.url') . '/' . $this->arrival_image_path;
        }

        return $this->arrival_image_url;
    }

    /**
     * Delete the arrival image from storage.
     */
    public function deleteArrivalImage(): void
    {
        if ($this->arrival_image_path) {
            \Illuminate\Support\Facades\Storage::disk('spaces')->delete($this->arrival_image_path);
        }

        $this->update([
            'arrival_image_path' => null,
            'arrival_image_filename' => null,
            'arrival_image_mime_type' => null,
            'arrival_image_size' => null,
            'arrival_image_url' => null,
        ]);
    }

}