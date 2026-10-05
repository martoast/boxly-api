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

    /** A user keeps at most this many chats (Alex 2026-10-05: "cap it at a maximum of 10 … deletes the oldest one"). */
    public const MAX_PER_USER = 10;

    /**
     * Delete the user's chats beyond MAX_PER_USER, least recently used first. Messages go with them (cascade);
     * a purchase request, cart or live session that pointed at one keeps working with no chat (nullOnDelete).
     */
    public static function trimForUser(int $userId): int
    {
        $extra = static::where('user_id', $userId)
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->skip(static::MAX_PER_USER)->take(PHP_INT_MAX)->pluck('id');
        if ($extra->isEmpty()) {
            return 0;
        }
        static::whereIn('id', $extra)->get()->each->delete();

        return $extra->count();
    }
}
