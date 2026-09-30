<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/** One open hour the shopping team offers. starts_at is UTC; shown in the in_person timezone (Pacific). */
class ShoppingSlot extends Model
{
    public const LOCATION = 'Las Americas Premium Outlets';

    protected $fillable = ['location', 'starts_at', 'created_by'];

    protected $casts = ['starts_at' => 'datetime'];

    public function local(): CarbonInterface
    {
        return $this->starts_at->copy()->setTimezone(config('services.in_person.timezone'));
    }
}
