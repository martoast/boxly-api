<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A package that arrived at the warehouse, read off its label photo. See the create_label_scans migration. */
class LabelScan extends Model
{
    protected $fillable = [
        'location',
        'batch',
        'tracking_number',
        'carrier',
        'other_tracking',
        'recipient_name',
        'suite',
        'user_id',
        'match_status',
        'ship_from',
        'store_order_numbers',
        'barcodes',
        'model_tracking_read',
        'confidence',
        'needs_check',
        'cleared_at',
        'image_path',
        'image_url',
        'created_by',
    ];

    protected $casts = [
        'other_tracking'      => 'array',
        'store_order_numbers' => 'array',
        'barcodes'            => 'array',
        'needs_check'         => 'boolean',
        'cleared_at'          => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The customer the label names (see App\Services\LabelScanMatcher), when one was matched or assigned. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
