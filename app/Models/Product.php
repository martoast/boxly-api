<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('display_order')->orderBy('id');
    }

    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_SOLD_OUT = 'sold_out';

    const BOX_THRESHOLDS = [
        'XS' => ['weight_kg' => 8, 'price_cents' => 120000],
        'S'  => ['weight_kg' => 15, 'price_cents' => 220000],
        'M'  => ['weight_kg' => 25, 'price_cents' => 400000],
        'L'  => ['weight_kg' => 35, 'price_cents' => 510000],
        'XL' => ['weight_kg' => 50, 'price_cents' => 625000],
    ];

    const STOCK_UNKNOWN = 'unknown';
    const STOCK_IN_STOCK = 'in_stock';
    const STOCK_OUT_OF_STOCK = 'out_of_stock';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'sku',
        'source_url',
        'price_cents',
        'cost_cents',
        'markup_percent',
        'weight_kg',
        'length_cm',
        'width_cm',
        'height_cm',
        'stock',
        'status',
        'available_until',
        'category',
        'images',
        'last_stock_check_at',
        'stock_check_status',
        'last_stock_check_response',
    ];

    protected $casts = [
        'price_cents'         => 'integer',
        'cost_cents'          => 'integer',
        'markup_percent'      => 'decimal:2',
        'weight_kg'           => 'decimal:2',
        'length_cm'           => 'decimal:1',
        'width_cm'            => 'decimal:1',
        'height_cm'           => 'decimal:1',
        'stock'               => 'integer',
        'available_until'     => 'datetime',
        'images'              => 'array',
        'last_stock_check_at' => 'datetime',
    ];

    protected $appends = ['price_formatted', 'first_image_url'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($product) {
            if (empty($product->slug)) {
                $product->slug = static::generateUniqueSlug($product->name);
            }
        });
    }

    public static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Listed = visible in the public storefront (out-of-stock items still shown
     * with WhatsApp CTA). Excludes drafts/inactive and expired clearance items.
     */
    public function scopeListed($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('available_until')->orWhere('available_until', '>', now());
            });
    }

    /**
     * Available = purchasable right now. Listed + has stock + source not flagged out_of_stock.
     */
    public function scopeAvailable($query)
    {
        return $query->listed()
            ->where('stock', '>', 0)
            ->where('stock_check_status', '!=', self::STOCK_OUT_OF_STOCK);
    }

    public function scopeExpiringSoon($query, int $days = 7)
    {
        return $query->whereNotNull('available_until')
            ->where('available_until', '<=', now()->addDays($days))
            ->where('available_until', '>', now());
    }

    // Accessors
    public function getPriceFormattedAttribute(): string
    {
        return number_format($this->price_cents / 100, 2) . ' MXN';
    }

    public function getFirstImageUrlAttribute(): ?string
    {
        $images = $this->images ?? [];
        return $images[0]['url'] ?? null;
    }

    public function isAvailable(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) return false;
        if ($this->stock <= 0) return false;
        if ($this->available_until && $this->available_until->isPast()) return false;
        if ($this->isOutOfStock()) return false;
        return true;
    }

    /**
     * A product is out of stock if:
     *  - It has variants AND every variant is out_of_stock, OR
     *  - It has no variants AND the product-level stock_check_status is out_of_stock
     */
    public function isOutOfStock(): bool
    {
        $variantCount = $this->variants()->count();

        if ($variantCount > 0) {
            $availableCount = $this->variants()
                ->where('stock_check_status', '!=', self::STOCK_OUT_OF_STOCK)
                ->count();
            return $availableCount === 0;
        }

        return $this->stock_check_status === self::STOCK_OUT_OF_STOCK;
    }
}
