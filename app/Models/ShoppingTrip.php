<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShoppingTrip extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'location',
        'trip_date',
        'start_time',
        'end_time',
        'status',
        'notes',
    ];

    protected $casts = [
        // 'date:Y-m-d' so JSON serialises as "2026-05-25" not the full ISO
        // timestamp — the trip is a calendar day, has no time component,
        // and frontends were choking on the trailing T00:00:00.000000Z.
        'trip_date' => 'date:Y-m-d',
    ];

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN)
            ->whereDate('trip_date', '>=', now()->toDateString())
            ->orderBy('trip_date');
    }
}
