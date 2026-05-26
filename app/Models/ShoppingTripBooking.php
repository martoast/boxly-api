<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ShoppingTripBooking extends Model
{
    protected $fillable = [
        'user_id',
        'shopping_trip_id',
        'store_ids',
        'store_categories',
        'booking_number',
        'deposit_amount_usd',
        'stripe_checkout_session_id',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'store_ids'         => 'array',
        'store_categories'  => 'array',
        'deposit_amount_usd'=> 'decimal:2',
        'paid_at'           => 'datetime',
    ];

    const STATUS_PENDING_PAYMENT = 'pending_payment';
    const STATUS_CONFIRMED       = 'confirmed';
    const STATUS_CANCELLED       = 'cancelled';

    public static function generateBookingNumber(): string
    {
        return 'BK-' . date('y') . '-' . strtoupper(Str::random(5));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shoppingTrip(): BelongsTo
    {
        return $this->belongsTo(ShoppingTrip::class);
    }
}
