<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * C5 — the engine's checkout quote for one store of a finalized cart.
 */
class StoreQuote extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    /** Terminal: nothing more will happen to the row. */
    public const TERMINAL = [self::STATUS_VERIFIED, self::STATUS_PARTIAL, self::STATUS_FAILED];

    /** A total the customer can be invoiced for. */
    public const BILLABLE = [self::STATUS_VERIFIED, self::STATUS_PARTIAL];

    public const MONEY = ['merchandise', 'discounts', 'shipping', 'tax', 'fees', 'total'];

    protected $fillable = [
        'purchase_request_id', 'cart_id', 'store_id', 'store_name', 'status', 'live_shopping_session_id',
        'attempts', 'currency', 'merchandise_cents', 'discounts_cents', 'shipping_cents', 'tax_cents',
        'fees_cents', 'total_cents', 'estimated', 'destination_verified', 'checkout_stage', 'evidence',
        'observed_at', 'error_code',
    ];

    protected $casts = [
        'estimated' => 'boolean',
        'destination_verified' => 'boolean',
        'evidence' => 'array',
        'observed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * The quotes of a purchase request for its detail payload: customers see
     * each store's status and money; the team also sees the evidence and why a
     * quote failed. [] when there are none (or before the table exists).
     */
    public static function payloadFor(PurchaseRequest $pr, bool $forTeam): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('store_quotes')) {
            return [];
        }

        return $pr->storeQuotes()->get()->map(function (self $q) use ($forTeam) {
            $row = [
                'store_id'    => $q->store_id,
                'store_name'  => $q->store_name,
                'status'      => $q->status,
                'currency'    => $q->currency,
                'estimated'   => $q->estimated,
                'observed_at' => optional($q->observed_at)->toIso8601String(),
            ];
            foreach (self::MONEY as $part) {
                $row["{$part}_cents"] = $q->{"{$part}_cents"};
            }
            if ($forTeam) {
                $row += [
                    'evidence'             => $q->evidence ?? [],
                    'error_code'           => $q->error_code,
                    'attempts'             => $q->attempts,
                    'destination_verified' => $q->destination_verified,
                    'checkout_stage'       => $q->checkout_stage,
                ];
            }

            return $row;
        })->values()->all();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }
}
