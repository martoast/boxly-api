<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function transactions(): HasMany
    {
        return $this->hasMany(WarChestTransaction::class, 'account_id');
    }

    public static function forMethod(?string $paymentMethod): ?self
    {
        if (empty($paymentMethod)) {
            return null;
        }
        return static::where('payment_method', $paymentMethod)->first();
    }

    /**
     * Adjust this account's balance by a signed delta and RECORD a ledger
     * transaction. Positive = money in, negative = money out. Returns the
     * created transaction (or null when delta is zero).
     *
     * $source keys: source_type, source_id, description, occurred_at, created_by.
     */
    public function move(float $delta, array $source = []): ?WarChestTransaction
    {
        if ($delta == 0.0) {
            return null;
        }

        $this->current_balance = round((float) $this->current_balance + $delta, 2);
        $this->save();

        return $this->transactions()->create([
            'direction' => $delta >= 0 ? 'in' : 'out',
            'amount' => round(abs($delta), 2),
            'balance_after' => $this->current_balance,
            'source_type' => $source['source_type'] ?? 'manual',
            'source_id' => $source['source_id'] ?? null,
            'description' => $source['description'] ?? null,
            'occurred_at' => $source['occurred_at'] ?? now(),
            'created_by' => $source['created_by'] ?? null,
        ]);
    }

    /**
     * Route a signed delta to the account for $paymentMethod, recording a
     * ledger transaction. No-ops safely when the method is empty or has no
     * matching account, so order/expense flows never fail if it isn't set up.
     */
    public static function applyDelta(?string $paymentMethod, float $delta, array $source = []): ?WarChestTransaction
    {
        $account = static::forMethod($paymentMethod);
        if (!$account) {
            return null;
        }

        $txn = $account->move($delta, $source);

        Log::info('War chest balance adjusted', [
            'account_id' => $account->id,
            'payment_method' => $paymentMethod,
            'delta' => $delta,
            'new_balance' => $account->current_balance,
        ]);

        return $txn;
    }
}
