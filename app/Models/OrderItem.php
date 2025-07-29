<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_url',
        'product_name',
        'product_image_url',
        'retailer',
        'quantity',
        'declared_value',
        'tracking_number',
        'tracking_url',
        'carrier',
        'arrived',
        'arrived_at',
        'weight',
        'dimensions',
        // New proof of purchase fields
        'proof_of_purchase_path',
        'proof_of_purchase_filename',
        'proof_of_purchase_mime_type',
        'proof_of_purchase_size',
        'proof_of_purchase_url',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'declared_value' => 'decimal:2',
        'arrived' => 'boolean',
        'arrived_at' => 'datetime',
        'weight' => 'decimal:2',
        'dimensions' => 'array',
        'proof_of_purchase_size' => 'integer',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['proof_of_purchase_full_url'];

    /**
     * Carrier constants
     */
    const CARRIERS = [
        'ups' => 'UPS',
        'fedex' => 'FedEx',
        'usps' => 'USPS',
        'amazon' => 'Amazon',
        'dhl' => 'DHL',
        'ontrac' => 'OnTrac',
        'lasership' => 'LaserShip',
        'other' => 'Other',
        'unknown' => 'Unknown',
    ];

    /**
     * Get the order that owns the item.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Mark item as arrived
     */
    public function markAsArrived(): void
    {
        $this->update([
            'arrived' => true,
            'arrived_at' => now(),
        ]);
        
        // Check if all items have arrived
        $this->order->checkAndUpdatePackageStatus();
    }

    /**
     * Mark item as not arrived
     */
    public function markAsNotArrived(): void
    {
        $this->update([
            'arrived' => false,
            'arrived_at' => null,
        ]);
    }

    /**
     * Update weight and dimensions
     */
    public function updateMeasurements(float $weight, ?array $dimensions = null): void
    {
        $data = ['weight' => $weight];
        
        if ($dimensions) {
            $data['dimensions'] = $dimensions;
        }
        
        $this->update($data);
        
        // Update order's total weight
        $this->order->update([
            'total_weight' => $this->order->calculateTotalWeight()
        ]);
    }

    /**
     * Get total value (declared_value * quantity)
     */
    public function getTotalValueAttribute(): float
    {
        return $this->declared_value * $this->quantity;
    }

    /**
     * Extract retailer from URL
     */
    public function extractRetailer(): ?string
    {
        $url = parse_url($this->product_url, PHP_URL_HOST);
        
        if (!$url) return null;
        
        $retailers = [
            'amazon.com' => 'Amazon',
            'ebay.com' => 'eBay',
            'walmart.com' => 'Walmart',
            'target.com' => 'Target',
            'bestbuy.com' => 'Best Buy',
            'homedepot.com' => 'Home Depot',
            'lowes.com' => 'Lowes',
            'costco.com' => 'Costco',
            'samsclub.com' => 'Sams Club',
            'macys.com' => 'Macys',
            'nordstrom.com' => 'Nordstrom',
            'zappos.com' => 'Zappos',
            'nike.com' => 'Nike',
            'adidas.com' => 'Adidas',
            'apple.com' => 'Apple',
        ];
        
        foreach ($retailers as $domain => $name) {
            if (str_contains($url, $domain)) {
                return $name;
            }
        }
        
        return ucfirst(str_replace('www.', '', $url));
    }

    /**
     * Detect carrier from tracking number
     */
    public function detectCarrier(): ?string
    {
        if (!$this->tracking_number) return null;
        
        // Remove spaces and convert to uppercase
        $tracking = strtoupper(str_replace(' ', '', $this->tracking_number));
        
        // UPS: 1Z followed by 16 more chars
        if (preg_match('/^1Z[0-9A-Z]{16}$/', $tracking)) {
            return 'ups';
        }
        
        // FedEx: 12 or 15 digits
        if (preg_match('/^\d{12}$|^\d{15}$/', $tracking)) {
            return 'fedex';
        }
        
        // USPS: 20-22 digits, or starts with 94
        if (preg_match('/^94\d{20}$|^\d{20,22}$/', $tracking)) {
            return 'usps';
        }
        
        // Amazon: TBA followed by digits
        if (preg_match('/^TBA\d+$/', $tracking)) {
            return 'amazon';
        }
        
        // DHL: 10 digits
        if (preg_match('/^\d{10}$/', $tracking)) {
            return 'dhl';
        }
        
        return 'unknown';
    }

    /**
     * Get tracking URL based on carrier
     */
    public function getTrackingUrlAttribute(): ?string
    {
        // If we already have a tracking URL, return it
        if ($this->attributes['tracking_url'] ?? null) {
            return $this->attributes['tracking_url'];
        }
        
        // Otherwise, build it from carrier and tracking number
        if (!$this->carrier || !$this->tracking_number) {
            return null;
        }
        
        $urls = [
            'ups' => "https://www.ups.com/track?tracknum={$this->tracking_number}",
            'fedex' => "https://www.fedex.com/fedextrack/?trknbr={$this->tracking_number}",
            'usps' => "https://tools.usps.com/go/TrackConfirmAction?tLabels={$this->tracking_number}",
            'amazon' => "https://www.amazon.com/gp/your-account/order-details?orderID={$this->tracking_number}",
            'dhl' => "https://www.dhl.com/en/express/tracking.html?AWB={$this->tracking_number}",
        ];
        
        return $urls[$this->carrier] ?? null;
    }

    /**
     * Get carrier display name
     */
    public function getCarrierNameAttribute(): string
    {
        return self::CARRIERS[$this->carrier] ?? 'Unknown';
    }

    /**
     * Check if item is ready for consolidation
     */
    public function isReady(): bool
    {
        return $this->arrived && $this->weight !== null;
    }

    /**
     * Get the full URL for the proof of purchase file
     */
    public function getProofOfPurchaseFullUrlAttribute(): ?string
    {
        if (!$this->proof_of_purchase_url) {
            return null;
        }
        
        // If it's already a full URL, return it
        if (filter_var($this->proof_of_purchase_url, FILTER_VALIDATE_URL)) {
            return $this->proof_of_purchase_url;
        }
        
        // Otherwise, prepend the DO Spaces URL
        return config('filesystems.disks.spaces.url') . '/' . $this->proof_of_purchase_url;
    }

    /**
     * Delete the proof of purchase file
     */
    public function deleteProofOfPurchase(): void
    {
        if ($this->proof_of_purchase_path) {
            Storage::disk('spaces')->delete($this->proof_of_purchase_path);
            
            $this->update([
                'proof_of_purchase_path' => null,
                'proof_of_purchase_filename' => null,
                'proof_of_purchase_mime_type' => null,
                'proof_of_purchase_size' => null,
                'proof_of_purchase_url' => null,
            ]);
        }
    }

    /**
     * Scope for arrived items
     */
    public function scopeArrived($query)
    {
        return $query->where('arrived', true);
    }

    /**
     * Scope for pending items
     */
    public function scopePending($query)
    {
        return $query->where('arrived', false);
    }

    /**
     * Scope for items missing measurements
     */
    public function scopeMissingMeasurements($query)
    {
        return $query->whereNull('weight');
    }

    /**
     * Boot method to set defaults
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($item) {
            // Auto-detect retailer if not set
            if (!$item->retailer) {
                $item->retailer = $item->extractRetailer();
            }
            
            // Auto-detect carrier if not set
            if (!$item->carrier && $item->tracking_number) {
                $item->carrier = $item->detectCarrier();
            }
        });
        
        // Clean up file when deleting item
        static::deleting(function ($item) {
            $item->deleteProofOfPurchase();
        });
    }
}