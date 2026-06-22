<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One article in the admin-managed knowledge wiki that powers the AI assistant's
 * business Q&A. Curated by the team; the published set is assembled and injected
 * into the assistant's system prompt.
 */
class KnowledgeArticle extends Model
{
    /** Cache key for the assembled published wiki (busted on any write). */
    public const CACHE_KEY = 'knowledge:published:v1';

    protected $fillable = [
        'title',
        'slug',
        'section',
        'content',
        'sort_order',
        'is_published',
        'updated_by',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order'   => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($article) {
            if (empty($article->slug)) {
                $article->slug = static::generateUniqueSlug($article->title);
            }
        });
    }

    public static function generateUniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'articulo';
        $slug = $base;
        $i = 1;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }
}
