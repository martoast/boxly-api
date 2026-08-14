<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class OrderBox extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'stripe_price_id',
        'stripe_product_id',
        'box_size',
        'box_name',
        'box_price',
        'currency',
        'quantity',
        // Boxly Protection — optional per-box theft/loss/damage cover.
        // Price is snapshotted at sale time, like box_price.
        'has_protection',
        'protection_price',
        'protection_price_id',
        'protection_product_id',
        // Dimensions and weight
        'length',
        'width',
        'height',
        'weight',
        // What the courier charged US to move this box (MXN). The same money as
        // the shipping business_expense rows — recorded per box so margin can be
        // read by size. Never add it to the expense total.
        'shipping_cost',
        // GIA file fields (one per box)
        'guia_number',
        'gia_path',
        'gia_filename',
        'gia_mime_type',
        'gia_size',
        'gia_url',
    ];

    protected $casts = [
        'box_price' => 'decimal:2',
        'quantity' => 'integer',
        'has_protection' => 'boolean',
        'protection_price' => 'decimal:2',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'weight' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'gia_size' => 'integer',
    ];

    protected $appends = [
        'gia_full_url',
        'protection_total',
    ];

    /**
     * Get the order that owns this box.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the total price for this box entry (price * quantity).
     */
    public function getTotalPriceAttribute(): float
    {
        return (float) $this->box_price * $this->quantity;
    }

    /**
     * What protection costs for this entry.
     *
     * Protection is priced per BOX, and one entry can represent several boxes
     * ("3x Medium"), so it multiplies by quantity just like the box price does.
     */
    public function getProtectionTotalAttribute(): float
    {
        if (! $this->has_protection) {
            return 0.0;
        }

        return (float) $this->protection_price * $this->quantity;
    }

    /**
     * Get a formatted description of this box.
     */
    public function getDescriptionAttribute(): string
    {
        if ($this->quantity > 1) {
            return "{$this->quantity}x {$this->box_name}";
        }
        return $this->box_name;
    }

    /**
     * Get the full URL for the GIA file.
     * Handles both relative and absolute URLs.
     */
    public function getGiaFullUrlAttribute(): ?string
    {
        if (!$this->gia_path && !$this->gia_url) {
            return null;
        }

        // If we have a full URL, return it
        if ($this->gia_url && str_starts_with($this->gia_url, 'http')) {
            return $this->gia_url;
        }

        // Build URL from path
        if ($this->gia_path) {
            return config('filesystems.disks.spaces.url') . '/' . $this->gia_path;
        }

        return $this->gia_url;
    }

    /**
     * Get formatted guia number (with spaces for readability).
     */
    public function getFormattedGuiaAttribute(): ?string
    {
        if (!$this->guia_number) {
            return null;
        }

        // Remove existing spaces and format as "XX XXXX XXXX"
        $clean = preg_replace('/\s+/', '', $this->guia_number);
        return trim(chunk_split($clean, 4, ' '));
    }

    /**
     * Get DHL tracking URL for this box's guia.
     */
    public function getDhlTrackingUrlAttribute(): ?string
    {
        if (!$this->guia_number) {
            return null;
        }

        $cleanGuia = preg_replace('/\s+/', '', $this->guia_number);
        return "https://www.dhl.com/mx-es/home/rastreo.html?tracking-id={$cleanGuia}";
    }

    /**
     * Delete the GIA file from storage and clear related fields.
     */
    public function deleteGia(): void
    {
        if ($this->gia_path) {
            try {
                Storage::disk('spaces')->delete($this->gia_path);
            } catch (\Exception $e) {
                // Log but don't throw - file might already be deleted
                \Log::warning('Failed to delete GIA file from storage', [
                    'box_id' => $this->id,
                    'path' => $this->gia_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->update([
            'gia_path' => null,
            'gia_filename' => null,
            'gia_mime_type' => null,
            'gia_size' => null,
            'gia_url' => null,
        ]);
    }

    /**
     * Check if this box has a GIA file uploaded.
     */
    public function hasGia(): bool
    {
        return !empty($this->gia_path) || !empty($this->gia_url);
    }
}
