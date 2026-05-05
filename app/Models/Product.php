<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('display_order')->orderBy('id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function categories(): BelongsToMany
    {
        // withTimestamps so attach/sync/syncWithoutDetaching populate
        // category_product.created_at / updated_at — the migration
        // declared NOT NULL timestamps, so without this the bulk
        // categorize endpoint 500s on the pivot insert.
        return $this->belongsToMany(Category::class)->withTimestamps();
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

    protected $fillable = [
        'name',
        'color',
        'slug',
        'description',
        'sku',
        'source_url',
        'requires_render',
        'price_cents',
        'cost_cents',
        'markup_percent',
        'weight_kg',
        'length_cm',
        'width_cm',
        'height_cm',
        'status',
        'available_until',
        'store_id',
        'images',
    ];

    protected $casts = [
        'requires_render' => 'boolean',
        'price_cents'     => 'integer',
        'cost_cents'      => 'integer',
        'markup_percent'  => 'decimal:2',
        'weight_kg'       => 'decimal:2',
        'length_cm'       => 'decimal:1',
        'width_cm'        => 'decimal:1',
        'height_cm'       => 'decimal:1',
        'available_until' => 'datetime',
        'images'          => 'array',
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
     * Available = purchasable right now. We no longer track inventory on the
     * Boxly side — Velonie verifies real-time availability at the source
     * retailer when reviewing each PR — so this is just an alias for listed().
     */
    public function scopeAvailable($query)
    {
        return $query->listed();
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
        return true;
    }
}
