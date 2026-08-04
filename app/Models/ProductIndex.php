<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One resolved product, remembered. See the migration for why this exists.
 */
class ProductIndex extends Model
{
    protected $table = 'product_index';

    protected $fillable = [
        'canonical_key',
        'identifiers',
        'title',
        'brand',
        'variant',
        'image',
        'store',
        'source_url',
        'payload',
        'resolved_at',
        'hits',
    ];

    protected $casts = [
        'identifiers' => 'array',
        'payload' => 'array',
        'resolved_at' => 'datetime',
        'hits' => 'integer',
    ];
}
