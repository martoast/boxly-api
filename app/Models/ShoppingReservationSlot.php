<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An hour locked by a paid reservation. active_slot_id is UNIQUE: that is the no-double-booking guarantee. */
class ShoppingReservationSlot extends Model
{
    public $timestamps = false;

    protected $fillable = ['reservation_id', 'shopping_slot_id', 'active_slot_id'];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(ShoppingReservation::class, 'reservation_id');
    }
}
