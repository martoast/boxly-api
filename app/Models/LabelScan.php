<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A package that arrived at the warehouse, read off its label photo. See the create_label_scans migration. */
class LabelScan extends Model
{
    protected $fillable = [
        'batch',
        'tracking_number',
        'carrier',
        'other_tracking',
        'recipient_name',
        'suite',
        'ship_from',
        'store_order_numbers',
        'barcodes',
        'model_tracking_read',
        'confidence',
        'needs_check',
        'image_path',
        'image_url',
        'created_by',
    ];

    protected $casts = [
        'other_tracking'      => 'array',
        'store_order_numbers' => 'array',
        'barcodes'            => 'array',
        'needs_check'         => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
