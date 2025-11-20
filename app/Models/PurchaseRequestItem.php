<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class PurchaseRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_request_id',
        'product_name',
        'product_url',
        'product_image_url', // Original optional URL field
        'price',
        'quantity',
        'options',
        'notes',
        
        // New File Upload Fields
        'image_path',
        'image_filename',
        'image_mime_type',
        'image_size',
        'image_url',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'integer',
        'options' => 'array',
        'image_size' => 'integer',
    ];

    protected $appends = ['image_full_url'];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function orderItem(): HasOne
    {
        return $this->hasOne(OrderItem::class);
    }

    /**
     * Get the full URL for the uploaded image
     */
    public function getImageFullUrlAttribute(): ?string
    {
        // 1. Prefer uploaded file URL
        if ($this->image_url) {
            // If it's already a full URL, return it
            if (filter_var($this->image_url, FILTER_VALIDATE_URL)) {
                return $this->image_url;
            }
            // Otherwise prepend storage URL (S3/Spaces)
            return config('filesystems.disks.spaces.url') . '/' . $this->image_url;
        }

        // 2. Fallback to manually entered URL string
        return $this->product_image_url;
    }

    /**
     * Delete the image file from storage
     */
    public function deleteImage(): void
    {
        if ($this->image_path) {
            Storage::disk('spaces')->delete($this->image_path);
            
            $this->update([
                'image_path' => null,
                'image_filename' => null,
                'image_mime_type' => null,
                'image_size' => null,
                'image_url' => null,
            ]);
        }
    }

    protected static function boot()
    {
        parent::boot();
        
        // Auto-delete file when record is deleted
        static::deleting(function ($item) {
            $item->deleteImage();
        });
    }
}