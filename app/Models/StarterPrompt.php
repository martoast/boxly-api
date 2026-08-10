<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StarterPrompt extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'prompt_text',
        'image_url',
        'image_query',
        'resolved_image_url',
        'emoji',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
