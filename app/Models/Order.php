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
        'estimated_delivery_date' => 'date',
        'actual_delivery_date' => 'date',
        'completed_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'quote_sent_at' => 'datetime',
        'quote_expires_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'gia_size' => 'integer',
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
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_SHIPPED => 'Shipped',
            self::STATUS_DELIVERED => 'Delivered',
            self::STATUS_AWAITING_PAYMENT => 'Awaiting Final Payment',
            self::STATUS_PAID => 'Paid',
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

        $this->update([
            'status' => self::STATUS_AWAITING_PACKAGES,
            'completed_at' => now(),
        ]);

        if ($this->allItemsArrived()) {
            $this->update([
                'status' => self::STATUS_PACKAGES_COMPLETE,
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
        if ($this->status === self::STATUS_AWAITING_PACKAGES && $this->allItemsArrived()) {
            $this->update([
                'status' => self::STATUS_PACKAGES_COMPLETE,
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
}