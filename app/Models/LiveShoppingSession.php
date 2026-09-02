<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One live shopping session. No business logic lives here — every transition is
 * made inside the result job's transaction (§7) or the reconciler (§7b), so
 * there is exactly one place where a status and its active_slot change together.
 */
class LiveShoppingSession extends Model
{
    protected $fillable = [
        'user_id', 'conversation_id', 'engine_session_id', 'status', 'store_id',
        'stores', 'objective', 'expires_at', 'latest_seq', 'terminal_seq',
        'terminal_delivery_id', 'error_code', 'active_slot',
    ];

    protected $casts = [
        'stores' => 'array',
        'expires_at' => 'datetime',
        'latest_seq' => 'integer',
        'terminal_seq' => 'integer',
    ];

    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Non-terminal states. `running` is reachable: pending from INSERT, running
     * once the engine confirms DURABLE acceptance (not merely "we sent it"). */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
