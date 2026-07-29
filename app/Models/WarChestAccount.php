<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarChestAccount extends Model
{
    protected $fillable = [
        'name',
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

    /**
     * Adjust this account's balance by a signed delta and RECORD a ledger
     * transaction. Positive = money in, negative = money out. Returns the
     * created transaction (or null when delta is zero).
     *
     * Balances are admin-maintained only — nothing in the app moves them
     * automatically. This is called by the manual-entry endpoint.
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
}
