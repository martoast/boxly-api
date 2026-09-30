<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingReservation extends Model
{
    public const PENDING = 'pending_payment';
    public const CONFIRMED = 'confirmed';
    public const SLOT_TAKEN = 'slot_taken';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';
    public const COMPLETED = 'completed';

    public const REASON_HOUR_UNAVAILABLE = 'hour_unavailable';
    public const REASON_PAID_FIRST = 'paid_first';

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'confirmation_sent_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'final_invoice_sent_at' => 'datetime',
        'final_paid_at' => 'datetime',
    ];

    /** Paid money that must go back to the customer: slot_taken, or cancelled after paying. */
    public function needsRefund(): bool
    {
        return $this->status === self::SLOT_TAKEN || ($this->status === self::CANCELLED && $this->paid_at !== null);
    }

    public function refundPending(): bool
    {
        return $this->needsRefund() && $this->refunded_at === null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Start of the first reserved hour in the in_person timezone (Pacific). */
    public function local(): CarbonInterface
    {
        return $this->starts_at->copy()->setTimezone(config('services.in_person.timezone'));
    }

    public function endsAt(): CarbonInterface
    {
        return $this->starts_at->copy()->addHours($this->hours_reserved);
    }

    public function dateLabel(string $locale = 'es'): string
    {
        $date = $this->local()->locale($locale);

        return $locale === 'es' ? $date->isoFormat('dddd D [de] MMMM') : $date->isoFormat('dddd, MMMM D');
    }

    public function startLabel(): string
    {
        return $this->local()->format('H:i');
    }

    /** End of the LAST reserved hour, local. */
    public function endLabel(): string
    {
        return $this->endsAt()->setTimezone(config('services.in_person.timezone'))->format('H:i');
    }

    /** Final bill: hours + commission - the amount already paid, never below 0. Null until completed. */
    public function final(): ?array
    {
        if ($this->status !== self::COMPLETED || $this->hours_worked === null || $this->amount_spent_usd === null) {
            return null;
        }
        $hours = (float) $this->hours_worked;
        $spent = (float) $this->amount_spent_usd;
        $percent = (float) config('services.in_person.commission_percent');
        $hoursFee = round($hours * (float) config('services.in_person.hourly_rate_usd'), 2);
        $commission = round($spent * $percent / 100, 2);
        $credit = (float) $this->amount_usd;

        return [
            'hours_worked' => $hours,
            'amount_spent_usd' => $spent,
            'hours_fee_usd' => $hoursFee,
            'commission_percent' => $percent,
            'commission_usd' => $commission,
            'credit_usd' => $credit,
            'total_usd' => max(0.0, round($hoursFee + $commission - $credit, 2)),
            'status' => $this->final_paid_at ? ($this->final_invoice_id ? 'paid' : 'settled') : ($this->final_invoice_id ? 'sent' : 'pending'),
            'invoice_url' => $this->final_invoice_url,
            'sent_at' => $this->final_invoice_sent_at?->toIso8601String(),
            'paid_at' => $this->final_paid_at?->toIso8601String(),
        ];
    }

    /** Chronological timeline built from the timestamps that exist. */
    public function events(): array
    {
        $events = [];
        foreach ([
            'reserved' => $this->created_at, 'paid' => $this->paid_at, 'refunded' => $this->refunded_at,
            'cancelled' => $this->cancelled_at, 'completed' => $this->completed_at,
            'final_invoice_sent' => $this->final_invoice_sent_at, 'final_invoice_paid' => $this->final_paid_at,
        ] as $type => $at) {
            if ($at) {
                $events[] = ['type' => $type, 'at' => $at->copy()->utc()->toIso8601String(), 'label_key' => 'in_person.event.' . $type];
            }
        }
        usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $events;
    }

    public function toApi(bool $team = false): array
    {
        $data = [
            'reservation_number' => $this->reservation_number,
            'status' => $this->status,
            'date' => $this->local()->toDateString(),
            'start_time' => $this->startLabel(),
            'end_time' => $this->endLabel(),
            'hours_reserved' => (int) $this->hours_reserved,
            'amount_usd' => (float) $this->amount_usd,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'location' => ShoppingSlot::LOCATION,
            'customer_notes' => $this->customer_notes,
            'whatsapp' => config('services.in_person.whatsapp'),
            'refunded' => $this->refunded_at !== null,
            'slot_taken_reason' => $this->slot_taken_reason,
            'refund_pending' => $this->refundPending(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'final' => $this->final(),
            'events' => $this->events(),
        ];

        if ($team) {
            $data += [
                'id' => $this->id,
                'refunded_at' => $this->refunded_at?->toIso8601String(),
                'stripe_payment_intent_id' => $this->stripe_payment_intent_id,
                'cancel_reason' => $this->cancel_reason,
                'hours_worked' => $this->hours_worked !== null ? (float) $this->hours_worked : null,
                'amount_spent_usd' => $this->amount_spent_usd !== null ? (float) $this->amount_spent_usd : null,
                'customer' => $this->user ? [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'phone' => $this->user->phone,
                ] : null,
            ];
        }

        return $data;
    }
}
