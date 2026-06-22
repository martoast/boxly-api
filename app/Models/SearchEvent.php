<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchEvent extends Model
{
    protected $fillable = [
        'user_id', 'type', 'query', 'store', 'title', 'url', 'results', 'results_sample',
    ];

    protected $casts = [
        'results' => 'integer',
        'results_sample' => 'array',
    ];

    public const TYPE_SEARCH = 'search';
    public const TYPE_PRODUCT_VIEW = 'product_view';
}
