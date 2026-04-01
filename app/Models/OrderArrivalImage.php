<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderArrivalImage extends Model
{
    protected $fillable = [
        'order_id',
        'type',
        'path',
        'url',
        'filename',
        'mime_type',
        'size',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
