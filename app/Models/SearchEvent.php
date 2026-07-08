<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchEvent extends Model
{
    protected $fillable = [
        'user_id', 'conversation_id', 'type', 'query', 'store', 'title', 'url', 'results', 'results_sample',
    ];

    protected $casts = [
        'results' => 'integer',
        'results_sample' => 'array',
    ];

    public const TYPE_SEARCH = 'search';
    public const TYPE_PRODUCT_VIEW = 'product_view';
    public const TYPE_QUESTION = 'question';

    /** The customer who made this search/question (null = guest). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The chat thread this event happened in (null = pre-linking / guest). */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
