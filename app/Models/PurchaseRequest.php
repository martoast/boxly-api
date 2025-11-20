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
        'items_total',
        'shipping_cost',
        'sales_tax',
        'processing_fee',
        'total_amount',
        'currency',
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

    public static function generateRequestNumber(): string
    {
        return 'PR-' . date('y') . '-' . strtoupper(Str::random(5));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }
}