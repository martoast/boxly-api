<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's Boxly cart. At most one `open` cart per user AND CHAT (Alex
 * 2026-10-03: a new chat is a new order) — the database enforces it through
 * UNIQUE(user_id, conversation_key, active_slot): conversation_key is the chat's
 * id (0 = no chat), active_slot is 1 while open and must be nulled in the same
 * write that moves status away from open (so the same chat's next add opens a
 * fresh cart — a new purchase request).
 */
class Cart extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = ['user_id', 'status', 'active_slot', 'conversation_id', 'conversation_key', 'purchase_request_id'];

    protected $casts = [
        'active_slot' => 'integer',
        'conversation_key' => 'integer',
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
