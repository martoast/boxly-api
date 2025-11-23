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
        'product_image_url', // Can be external URL or link to uploaded file
        'retailer',
        'quantity',
        'declared_value',
        'tracking_number',
        'tracking_url',
        'carrier',
        'estimated_delivery_date',
        'arrived',
        'arrived_at',
        'weight',
        'dimensions',
        
        // Proof of purchase fields
        'proof_of_purchase_path',
        'proof_of_purchase_filename',
        'proof_of_purchase_mime_type',
        'proof_of_purchase_size',
        'proof_of_purchase_url',

        // New Product Image File fields
        'product_image_path',
        'product_image_filename',
        'product_image_mime_type',
        'product_image_size',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'declared_value' => 'decimal:2',
        'arrived' => 'boolean',
        'arrived_at' => 'datetime',
        'estimated_delivery_date' => 'date',
        'weight' => 'decimal:2',
        'dimensions' => 'array',
        'proof_of_purchase_size' => 'integer',
        'product_image_size' => 'integer',
    ];

    protected $appends = ['proof_of_purchase_full_url', 'is_overdue'];

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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function getIsOverdueAttribute(): bool
    {
        if (!$this->estimated_delivery_date || $this->arrived) {
            return false;
        }
        return $this->estimated_delivery_date->isPast();
    }

    public function getDaysUntilDeliveryAttribute(): ?int
    {
        if (!$this->estimated_delivery_date || $this->arrived) {
            return null;
        }
        return now()->diffInDays($this->estimated_delivery_date, false);
    }

    public function markAsArrived(): void
    {
        $this->update([
            'arrived' => true,
            'arrived_at' => now(),
        ]);
        $this->order->checkAndUpdatePackageStatus();
    }

    public function markAsNotArrived(): void
    {
        $this->update([
            'arrived' => false,
            'arrived_at' => null,
        ]);
    }

    public function updateMeasurements(float $weight, ?array $dimensions = null): void
    {
        $data = ['weight' => $weight];
        if ($dimensions) {
            $data['dimensions'] = $dimensions;
        }
        $this->update($data);
        $this->order->update([
            'total_weight' => $this->order->calculateTotalWeight()
        ]);
    }

    public function getTotalValueAttribute(): float
    {
        return $this->declared_value * $this->quantity;
    }

    public function extractRetailer(): ?string
    {
        if (!$this->product_url) return null;
        
        $url = parse_url($this->product_url, PHP_URL_HOST);
        if (!$url) return null;
        
        $retailers = [
            'amazon.com' => 'Amazon',
            'ebay.com' => 'eBay',
            'walmart.com' => 'Walmart',
            'target.com' => 'Target',
            'bestbuy.com' => 'Best Buy',
            'shein.com' => 'Shein',
            'temu.com' => 'Temu',
            'aliexpress.com' => 'AliExpress',
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

    public function detectCarrier(): ?string
    {
        if (!$this->tracking_number) return null;
        
        $tracking = strtoupper(str_replace(' ', '', $this->tracking_number));
        
        if (preg_match('/^1Z[0-9A-Z]{16}$/', $tracking)) return 'ups';
        if (preg_match('/^\d{12}$|^\d{15}$/', $tracking)) return 'fedex';
        if (preg_match('/^94\d{20}$|^\d{20,22}$/', $tracking)) return 'usps';
        if (preg_match('/^TBA\d+$/', $tracking)) return 'amazon';
        if (preg_match('/^\d{10}$/', $tracking)) return 'dhl';
        
        return 'unknown';
    }

    public function getTrackingUrlAttribute(): ?string
    {
        if ($this->attributes['tracking_url'] ?? null) {
            return $this->attributes['tracking_url'];
        }
        
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

    public function getCarrierNameAttribute(): string
    {
        return self::CARRIERS[$this->carrier] ?? 'Unknown';
    }

    public function getProofOfPurchaseFullUrlAttribute(): ?string
    {
        if (!$this->proof_of_purchase_url) return null;
        if (filter_var($this->proof_of_purchase_url, FILTER_VALIDATE_URL)) return $this->proof_of_purchase_url;
        return config('filesystems.disks.spaces.url') . '/' . $this->proof_of_purchase_url;
    }

    // File Cleanup
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

    public function deleteProductImage(): void
    {
        if ($this->product_image_path) {
            Storage::disk('spaces')->delete($this->product_image_path);
            $this->update([
                'product_image_path' => null,
                'product_image_filename' => null,
                'product_image_mime_type' => null,
                'product_image_size' => null,
                // Don't verify URL here as it might be an external URL
                // If it matched the path, we could clear it, but safer to leave unless we want to reset to null
            ]);
        }
    }

    // Scopes
    public function scopeArrived($query) { return $query->where('arrived', true); }
    public function scopePending($query) { return $query->where('arrived', false); }
    public function scopeOverdue($query) {
        return $query->where('arrived', false)
            ->whereNotNull('estimated_delivery_date')
            ->whereDate('estimated_delivery_date', '<', now());
    }
    public function scopeArrivingSoon($query, $days = 3) {
        return $query->where('arrived', false)
            ->whereNotNull('estimated_delivery_date')
            ->whereDate('estimated_delivery_date', '>=', now())
            ->whereDate('estimated_delivery_date', '<=', now()->addDays($days));
    }
    public function scopeExpectedBy($query, $date) {
        return $query->whereNotNull('estimated_delivery_date')
            ->whereDate('estimated_delivery_date', '<=', $date);
    }

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($item) {
            if (!$item->retailer) $item->retailer = $item->extractRetailer();
            if (!$item->carrier && $item->tracking_number) $item->carrier = $item->detectCarrier();
        });
        
        static::deleting(function ($item) {
            $item->deleteProofOfPurchase();
            $item->deleteProductImage();
        });
    }
}