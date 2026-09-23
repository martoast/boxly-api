<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's Boxly cart. At most one `open` cart per user — the database
 * enforces it through UNIQUE(user_id, active_slot): active_slot is 1 while open
 * and must be nulled in the same write that moves status away from open.
 */
class Cart extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = ['user_id', 'status', 'active_slot', 'conversation_id', 'purchase_request_id'];

    protected $casts = [
        'active_slot' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    /** First-added first — store grouping and finalize both rely on this order. */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }
}
