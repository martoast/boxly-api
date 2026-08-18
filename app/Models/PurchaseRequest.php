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
        'conversation_id',
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
        'store_categories',
        'deposit_amount_usd',
        'deposit_checkout_session_id',
        'deposit_payment_link',
        'deposit_payment_link_id',
        'deposit_paid_at',
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
        // { "<store_id>": [<category_id>, ...] } — what the customer
        // wants us to look for at each selected store on an in-person PR.
        'store_categories' => 'array',
        'deposit_amount_usd' => 'decimal:2',
        'deposit_paid_at' => 'datetime',
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
    // In-person only: PR exists but the customer hasn't paid the $10/store
    // scheduling deposit yet. Won't appear in admin queues or the customer's
    // active PR list until the Stripe Checkout webhook flips it to
    // pending_review.
    const STATUS_AWAITING_DEPOSIT = 'awaiting_deposit';

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

    // In-person PRs let customers list stores they want us to visit.
    // Their category interests per store live in the `store_categories`
    // JSON column on the PR itself — not a separate pivot — so admin can
    // see "at Nike they want Sneakers + Sportswear, at Coach they want
    // Bags" rendered alongside each store card.
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'purchase_request_stores')
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

    public function depositPaid(): bool
    {
        return ! is_null($this->deposit_paid_at);
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

    /**
     * Per-store category breakdown for in-person PRs. Returns one row per
     * store the customer booked, each with the category names they marked
     * for *that* store. Used by mail templates, the admin PR detail panel,
     * and the customer's review screen — everywhere we render the in-person
     * intake. Categories are resolved by ID against the live catalog so
     * deactivated categories simply drop out of the list rather than
     * showing a stale label.
     *
     * Returns: Collection of [
     *   ['store' => Store, 'category_names' => ['Sneakers', 'Sportswear']],
     *   ['store' => Store, 'category_names' => []],     // no preference
     * ]
     */
    public function inPersonStoreBreakdown(): \Illuminate\Support\Collection
    {
        if (! $this->isInPerson()) {
            return collect();
        }

        $this->loadMissing('stores');

        $map = collect($this->store_categories ?? [])
            // JSON object keys come back as strings — normalise so int store_id
            // lookups hit. Empty-array values are kept as-is.
            ->mapWithKeys(fn ($cats, $sid) => [(int) $sid => array_map('intval', (array) $cats)]);

        $allIds = $map->flatten()->unique()->values();
        $namesById = $allIds->isEmpty()
            ? collect()
            : Category::whereIn('id', $allIds)->pluck('name', 'id');

        return $this->stores->map(fn ($store) => [
            'store'          => $store,
            'category_names' => collect($map->get($store->id, []))
                ->map(fn ($id) => $namesById->get($id))
                ->filter()
                ->values()
                ->all(),
        ]);
    }
}