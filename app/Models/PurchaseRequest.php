<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'shopping_trip_id',
        'items_total',
        'shipping_cost',
        'sales_tax',
        'store_costs',
        'processing_fee',
        'total_amount',
        'total_usd',
        'fx_rate_used',
        'currency',
        'payment_method',
        'stripe_invoice_id',
        'stripe_account',
        'payment_link',
        'quote_sent_at',
        'paid_at',
        'purchased_at',
        'admin_notes',
        'customer_notes',
        'minimum_budget_usd',
        'in_person_store_count',
    ];

    protected $casts = [
        'items_total' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'sales_tax' => 'decimal:2',
        'store_costs' => 'array',
        'processing_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'minimum_budget_usd' => 'decimal:2',
        'in_person_store_count' => 'integer',
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
    const SOURCE_IN_PERSON = 'in_person';

    // Which Stripe account this PR's invoice lives on. Null/'main' = legacy
    // (created before the shopping account was split out). 'shopping' = new.
    const STRIPE_ACCOUNT_MAIN = 'main';
    const STRIPE_ACCOUNT_SHOPPING = 'shopping';

    /**
     * Return the Stripe SDK client whose API keys match the account this
     * PR's invoice was created on. Used everywhere we retrieve / void /
     * inspect the invoice so we don't accidentally hit the wrong account.
     */
    public function stripeClient(): \Stripe\StripeClient
    {
        return $this->stripe_account === self::STRIPE_ACCOUNT_SHOPPING
            ? \App\Services\StripeAccount::shopping()
            : \App\Services\StripeAccount::main();
    }

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

    public function shoppingTrip(): BelongsTo
    {
        return $this->belongsTo(ShoppingTrip::class);
    }

    // In-person PRs let customers list stores they want us to visit and
    // categories they're interested in. Pivot tables wire both up — no
    // extra columns on the pivots; presence is the whole signal.
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'purchase_request_stores')
            ->withTimestamps();
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'purchase_request_categories')
            ->withTimestamps();
    }

    // ---- Source-aware helpers ----

    public function isStore(): bool
    {
        return $this->source === self::SOURCE_STORE;
    }

    public function isInPerson(): bool
    {
        return $this->source === self::SOURCE_IN_PERSON;
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