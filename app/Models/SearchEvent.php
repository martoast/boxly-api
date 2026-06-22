<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchEvent extends Model
{
    protected $fillable = [
        'user_id', 'type', 'query', 'store', 'title', 'url', 'results',
    ];

    protected $casts = [
        'results' => 'integer',
    ];

    public const TYPE_SEARCH = 'search';
    public const TYPE_PRODUCT_VIEW = 'product_view';
}
