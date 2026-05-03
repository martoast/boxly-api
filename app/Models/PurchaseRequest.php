<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PurchaseRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'request_number',
        'status',
        'source',
        'items_total',
        'shipping_cost',
        'sales_tax',
        'processing_fee',
        'total_amount',
        'currency',
        'payment_method',
        'stripe_invoice_id',
        'payment_link',
        'quote_sent_at',
        'paid_at',
        'purchased_at',
        'admin_notes',
    ];

    protected $casts = [
        'items_total' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'sales_tax' => 'decimal:2',
        'processing_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'quote_sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'purchased_at' => 'datetime',
    ];

    const STATUS_PENDING_REVIEW = 'pending_review';
    const STATUS_QUOTED = 'quoted';
    const STATUS_PAID = 'paid';
    const STATUS_PURCHASED = 'purchased';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

    const PAYMENT_METHOD_STRIPE = 'stripe';
    const PAYMENT_METHOD_MANUAL_DEPOSIT = 'manual_deposit';

    // Origin of the request: store-checkout (customer paid through /shop)
    // vs assisted (admin manually created on behalf of a customer).
    const SOURCE_STORE = 'store';
    const SOURCE_ASSISTED = 'assisted';

    public static function generateRequestNumber(): string
    {
        return 'PR-' . date('y') . '-' . strtoupper(Str::random(5));
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        return match($this->payment_method) {
            self::PAYMENT_METHOD_STRIPE => 'Stripe',
            self::PAYMENT_METHOD_MANUAL_DEPOSIT => 'Manual Bank Transfer (NU)',
            default => 'Unknown',
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    // ---- Source-aware helpers ----

    public function isStore(): bool
    {
        return $this->source === self::SOURCE_STORE;
    }

    /**
     * True only when every item has been stock-checked (available or unavailable).
     * Used as the gate for createQuote on store-source PRs.
     */
    public function allItemsStockChecked(): bool
    {
        return ! $this->items->contains(function ($item) {
            return $item->stock_status === PurchaseRequestItem::STOCK_UNVERIFIED;
        });
    }

    /**
     * Items eligible for billing — only the ones Velonie marked available.
     * Items marked unavailable stay on the PR (so the customer can see them)
     * but are excluded from the Stripe invoice line items.
     */
    public function availableItems()
    {
        return $this->items->filter(function ($item) {
            return $item->stock_status === PurchaseRequestItem::STOCK_AVAILABLE;
        })->values();
    }
}