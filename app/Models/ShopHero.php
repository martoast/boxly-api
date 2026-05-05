<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Editable hero banner for /shop. One row is the "current campaign";
 * older campaigns can stay in the table with is_active=false as
 * inspiration / rollback targets.
 */
class ShopHero extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'subtitle',
        'image_path',
        'image_url',
        'cta_label',
        'cta_link',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The hero the public storefront should render. Latest active row
     * wins so admin can stage a new campaign by creating a draft and
     * flipping is_active when ready.
     */
    public static function active(): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->latest('updated_at')
            ->first();
    }
}
