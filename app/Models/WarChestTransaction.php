<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarChestTransaction extends Model
{
    protected $fillable = [
        'account_id',
        'direction',
        'amount',
        'balance_after',
        'source_type',
        'source_id',
        'description',
        'occurred_at',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(WarChestAccount::class, 'account_id');
    }
}
