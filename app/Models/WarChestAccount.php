<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class WarChestAccount extends Model
{
    protected $fillable = [
        'name',
        'payment_method',
        'current_balance',
        'target_amount',
        'currency',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'current_balance' => 'decimal:2',
        'target_amount' => 'decimal:2',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Apply a signed delta to the account routed to $paymentMethod
     * (e.g. NU / HSBC / Stripe). Positive = money in, negative = money out.
     * No-ops safely when the method is empty or has no matching account, so
     * order/expense flows never fail because the war chest isn't set up.
     */
    public static function applyDelta(?string $paymentMethod, float $delta): void
    {
        if (empty($paymentMethod) || $delta == 0.0) {
            return;
        }

        $account = static::where('payment_method', $paymentMethod)->first();
        if (!$account) {
            return;
        }

        $account->current_balance = round((float) $account->current_balance + $delta, 2);
        $account->save();

        Log::info('War chest balance adjusted', [
            'account_id' => $account->id,
            'payment_method' => $paymentMethod,
            'delta' => $delta,
            'new_balance' => $account->current_balance,
        ]);
    }
}
