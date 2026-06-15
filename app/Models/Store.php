<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'base_url',
        'logo_url',
        'cover_image_url',
        'description',
        'is_active',
        'show_on_landing',
        'is_in_person_available',
        'sort_order',
    ];

    protected $casts = [
        'is_active'              => 'boolean',
        'show_on_landing'        => 'boolean',
        'is_in_person_available' => 'boolean',
        'sort_order'             => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($store) {
            if (empty($store->slug)) {
                $store->slug = static::generateUniqueSlug($store->name);
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInPersonAvailable($query)
    {
        return $query->where('is_in_person_available', true);
    }
}
