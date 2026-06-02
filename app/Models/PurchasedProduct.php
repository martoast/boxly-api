<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchasedProduct extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';
    const STATUS_DELIVERED = 'delivered';

    protected $fillable = [
        'user_id',
        'purchase_request_id',
        'customer_name',
        'contact_phone',
        'items',
        'order_number',
        'status',
        'order_date',
    ];

    protected $casts = [
        'order_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }
}
