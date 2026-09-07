<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = ['user_id', 'title', 'last_message_at', 'running_summary', 'summary_upto_message_id', 'summary_version', 'summary_updated_at'];

    protected $casts = [
        'last_message_at' => 'datetime',
        'summary_updated_at' => 'datetime',
        'summary_upto_message_id' => 'integer',
        'summary_version' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('id');
    }
}
