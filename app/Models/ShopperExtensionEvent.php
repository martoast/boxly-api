<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of the shopper extension's funnel.
 *
 * Carries no URL, no store and no product — see the migration for why that is a
 * commitment rather than an oversight.
 */
class ShopperExtensionEvent extends Model
{
    protected $fillable = ['user_id', 'kind', 'localized', 'gap_percent'];

    protected $casts = [
        'localized' => 'boolean',
        'gap_percent' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
