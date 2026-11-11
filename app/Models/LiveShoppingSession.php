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
        'user_id', 'conversation_id', 'engine_session_id', 'status', 'store_id', 'kind',
        'stores', 'objective', 'expires_at', 'latest_seq', 'terminal_seq',
        'terminal_delivery_id', 'error_code', 'active_slot', 'cart_id', 'cart_active_key',
    ];

    protected $casts = [
        'stores' => 'array',
        'expires_at' => 'datetime',
        'queued_at' => 'datetime',
        'latest_seq' => 'integer',
        'terminal_seq' => 'integer',
    ];

    /**
     * The shopper's search filters (brand, max_price, on_sale) as the engine takes them: nulls and false dropped, the brand trimmed,
     * the price a number. Null when nothing is left.
     */
    public static function cleanFilters(?array $f): ?array
    {
        if (! $f) {
            return null;
        }
        $out = [];
        if (isset($f['brand']) && trim((string) $f['brand']) !== '') {
            $out['brand'] = trim((string) $f['brand']);
        }
        if (isset($f['max_price']) && is_numeric($f['max_price'])) {
            $out['max_price'] = round((float) $f['max_price'], 2);
        }
        if (! empty($f['on_sale'])) {
            $out['on_sale'] = true;
        }

        return $out ?: null;
    }

    /** Kept for an hour (no column): a search queued behind a busy engine starts with the same filters. */
    public static function rememberFilters(int $id, array $filters): void
    {
        \Illuminate\Support\Facades\Cache::put("live_filters:{$id}", $filters, 3600);
    }

    public static function rememberedFilters(int $id): ?array
    {
        $f = \Illuminate\Support\Facades\Cache::get("live_filters:{$id}");

        return is_array($f) && $f ? $f : null;
    }

    /** Who drives the session: the agent (today's flow) or the customer (remote store browser). */
    public const KIND_AGENT  = 'agent';
    public const KIND_MANUAL = 'manual';
    public const KINDS = [self::KIND_AGENT, self::KIND_MANUAL];
    /** C3: a cart sync session, created only by SyncStoreCartJob — never a
     * customer-selectable kind, so it is deliberately NOT in KINDS. */
    public const KIND_CART = 'cart';

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

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
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
