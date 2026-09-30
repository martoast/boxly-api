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

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'confirmation_sent_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

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
