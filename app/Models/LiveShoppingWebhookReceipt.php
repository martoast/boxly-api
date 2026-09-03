<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One inbound terminal delivery, durably recorded before it is acknowledged.
 *
 * The row IS the work item: the job is handed a receipt id and nothing else, so
 * the payload must survive the HTTP response. status received -> processed under
 * a row lock is what makes a duplicate dispatch (queue retry, or the drainer
 * racing the fast path) a no-op.
 */
class LiveShoppingWebhookReceipt extends Model
{
    protected $fillable = [
        'delivery_id', 'content_sha256', 'payload', 'status',
        'live_shopping_session_id', 'outcome', 'terminal_seq', 'error_code',
        'attempts', 'received_at', 'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'terminal_seq' => 'integer',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public const STATUS_RECEIVED  = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_CONFLICT  = 'conflict';
    public const STATUS_FAILED    = 'failed';

    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveShoppingSession::class, 'live_shopping_session_id');
    }

    /**
     * Rows the drainer should pick up: still 'received' and older than the grace,
     * so it never races a job that is already in flight on the fast path.
     */
    public function scopeDrainable(Builder $query, int $graceSeconds): Builder
    {
        return $query->where('status', self::STATUS_RECEIVED)
            ->where('received_at', '<=', now()->subSeconds($graceSeconds))
            // Belt as well as braces: the job's orphan horizon already bounds
            // retries in time, and this bounds them in count.
            ->where('attempts', '<', 20);
    }
}
