<?php

namespace App\Services;

use App\Mail\InPersonReservationCancelled;
use App\Mail\InPersonReservationConfirmed;
use App\Mail\InPersonReservationManagerAlert;
use App\Mail\InPersonSlotTaken;
use App\Models\ShoppingReservation;
use App\Models\ShoppingReservationSlot;
use App\Models\ShoppingSlot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * In-person reservations: availability rules, Stripe Checkout, and the atomic
 * "first confirmed payment wins the hours" step. Nothing is held while a
 * customer pays; the hours are locked only when the payment is confirmed.
 */
class InPersonReservationService
{
    public const FIRST_HOUR = 6;
    public const LAST_HOUR = 22; // last slot starts at 22:00 and ends at 23:00

    public function __construct(private InPersonStripeGateway $stripe) {}

    public function tz(): string
    {
        return config('services.in_person.timezone');
    }

    public function now(): Carbon
    {
        return Carbon::now($this->tz());
    }

    public function maxHours(): int
    {
        return (int) config('services.in_person.max_hours');
    }

    /** Pacific local 'Y-m-d' + 'H:i' -> UTC Carbon. */
    public function utc(string $date, string $time): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', substr($date, 0, 10) . ' ' . substr($time, 0, 5), $this->tz())->utc();
    }

    /** UTC bounds of the local days from..to (inclusive). */
    public function utcRange(string $from, string $to): array
    {
        return [$this->utc($from, '00:00'), $this->utc($to, '00:00')->addDay()];
    }

    /** Open, future slots with no CONFIRMED reservation: local date => [local hour => slot id]. */
    public function availableByDate(string $from, string $to): array
    {
        [$start, $end] = $this->utcRange($from, $to);
        $slots = ShoppingSlot::where('location', ShoppingSlot::LOCATION)
            ->where('starts_at', '>=', $start)->where('starts_at', '<', $end)
            ->where('starts_at', '>', now())
            ->whereNotIn('id', ShoppingReservationSlot::whereNotNull('active_slot_id')->select('active_slot_id'))
            ->orderBy('starts_at')
            ->get();

        $byDate = [];
        foreach ($slots as $slot) {
            $local = $slot->local();
            $byDate[$local->toDateString()][(int) $local->format('G')] = $slot->id;
        }

        return $byDate;
    }

    /** @return array<int, array{date:string, slots:array}> */
    public function availability(string $from, string $to): array
    {
        $out = [];
        foreach ($this->availableByDate($from, $to) as $date => $hours) {
            $slots = [];
            foreach ($hours as $hour => $id) {
                $run = 0;
                while ($run < $this->maxHours() && isset($hours[$hour + $run])) {
                    $run++;
                }
                $slots[] = [
                    'id' => $id,
                    'start_time' => sprintf('%02d:00', $hour),
                    'end_time' => sprintf('%02d:00', $hour + 1),
                    'max_consecutive_hours' => $run,
                ];
            }
            $out[] = ['date' => $date, 'slots' => $slots];
        }

        return $out;
    }

    /** True when every one of the $hours hours starting at $startHour is open, future and not locked. */
    public function hoursAvailable(string $date, int $startHour, int $hours): bool
    {
        $available = $this->availableByDate($date, $date)[$date] ?? [];
        for ($h = $startHour; $h < $startHour + $hours; $h++) {
            if (! isset($available[$h])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create the pending reservation and its Stripe Checkout Session.
     * Returns [reservation, checkout_url]; throws on a Stripe failure (the pending row is deleted).
     */
    public function createReservation(User $user, string $date, string $startTime, int $hours, ?string $notes): array
    {
        $rate = (float) config('services.in_person.hourly_rate_usd');

        $reservation = ShoppingReservation::create([
            'reservation_number' => 'tmp-' . uniqid('', true),
            'user_id' => $user->id,
            'starts_at' => $this->utc($date, $startTime),
            'hours_reserved' => $hours,
            'status' => ShoppingReservation::PENDING,
            'amount_usd' => $rate,
            'customer_notes' => $notes,
        ]);
        $reservation->update(['reservation_number' => sprintf('RV-%s-%04d', $this->now()->year, $reservation->id)]);

        try {
            $front = config('app.frontend_url');
            $meta = ['type' => 'in_person_reservation', 'reservation_id' => (string) $reservation->id, 'reservation_number' => $reservation->reservation_number];
            $session = $this->stripe->createCheckoutSession([
                'mode' => 'payment',
                'customer' => $user->stripeShoppingCustomerId(),
                'client_reference_id' => (string) $reservation->id,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'usd',
                        'unit_amount' => (int) round($rate * 100),
                        'product_data' => [
                            'name' => sprintf(
                                'Reserva de compra personal — Las Américas — %s %s (primera hora; total reservado: %d h)',
                                $date, $reservation->startLabel(), $hours,
                            ),
                        ],
                    ],
                ]],
                'invoice_creation' => ['enabled' => true],
                'metadata' => $meta,
                'payment_intent_data' => ['metadata' => $meta],
                'success_url' => $front . '/in-person/success?ref=' . urlencode($reservation->reservation_number) . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $front . '/in-person?cancelled=1',
                // Stripe needs >= 30 min ahead; only here so stale payment pages die, it holds nothing.
                'expires_at' => now()->addMinutes(30)->addSeconds(30)->timestamp,
            ]);
            $reservation->update(['stripe_checkout_session_id' => $session->id]);
        } catch (\Throwable $e) {
            $reservation->delete();
            throw $e;
        }

        return [$reservation, $session->url];
    }

    /** checkout.session.completed / paid-session lookup: confirm the reservation once. */
    public function handlePaidSession(object $session): void
    {
        $id = $session->metadata->reservation_id ?? null;
        $reservation = $id ? ShoppingReservation::find($id) : null;
        if (! $reservation) {
            Log::warning('In-person reservation not found for paid checkout session', ['session_id' => $session->id ?? null]);

            return;
        }

        $this->confirm($reservation, $session->payment_intent ?? null, $session->invoice ?? null);
    }

    public function markExpired(object $session): void
    {
        $id = $session->metadata->reservation_id ?? null;
        if ($id) {
            ShoppingReservation::where('id', $id)->where('status', ShoppingReservation::PENDING)
                ->update(['status' => ShoppingReservation::EXPIRED]);
        }
    }

    /**
     * Atomic confirm: pending_payment -> confirmed plus the hour locks, in one
     * transaction. The first delivery flips the row (affected rows = 1); a replay
     * does nothing. If an hour is already locked (or was removed meanwhile) the
     * transaction rolls back and the reservation becomes slot_taken and is refunded.
     */
    public function confirm(ShoppingReservation $reservation, ?string $paymentIntent, ?string $invoice): void
    {
        $confirmed = false;
        try {
            DB::transaction(function () use ($reservation, $paymentIntent, $invoice, &$confirmed) {
                $flipped = ShoppingReservation::where('id', $reservation->id)
                    ->where('status', ShoppingReservation::PENDING)
                    ->update([
                        'status' => ShoppingReservation::CONFIRMED,
                        'paid_at' => now(),
                        'stripe_payment_intent_id' => $paymentIntent,
                        'stripe_invoice_id' => $invoice,
                        'confirmation_sent_at' => now(),
                    ]);
                if ($flipped !== 1) {
                    return;
                }

                $times = array_map(fn ($i) => $reservation->starts_at->copy()->addHours($i), range(0, $reservation->hours_reserved - 1));
                $slots = ShoppingSlot::where('location', ShoppingSlot::LOCATION)->whereIn('starts_at', $times)->get();
                if ($slots->count() !== count($times)) {
                    throw new \DomainException('An hour of the reservation is no longer offered');
                }
                foreach ($slots as $slot) {
                    ShoppingReservationSlot::create([
                        'reservation_id' => $reservation->id,
                        'shopping_slot_id' => $slot->id,
                        'active_slot_id' => $slot->id,
                    ]);
                }
                $confirmed = true;
            });
        } catch (UniqueConstraintViolationException|\DomainException $e) {
            $this->slotTaken($reservation, $paymentIntent);

            return;
        }

        if ($confirmed) {
            $this->afterConfirmed($reservation->fresh(['user']));
        }
    }

    private function slotTaken(ShoppingReservation $reservation, ?string $paymentIntent): void
    {
        $flipped = ShoppingReservation::where('id', $reservation->id)
            ->where('status', ShoppingReservation::PENDING)
            ->update(['status' => ShoppingReservation::SLOT_TAKEN, 'paid_at' => now(), 'stripe_payment_intent_id' => $paymentIntent]);
        if ($flipped !== 1) {
            return;
        }

        $reservation = $reservation->fresh(['user']);
        if ($paymentIntent) {
            try {
                $this->stripe->refundPaymentIntent($paymentIntent);
                $reservation->update(['refunded_at' => now()]);
            } catch (\Throwable $e) {
                // refunded_at stays null: the team sees slot_taken + not refunded = refund pending.
                Log::error('In-person slot_taken refund FAILED, refund manually', [
                    'reservation' => $reservation->reservation_number, 'payment_intent' => $paymentIntent, 'error' => $e->getMessage(),
                ]);
            }
        }

        $this->queue($reservation->user, new InPersonSlotTaken($reservation));
    }

    private function afterConfirmed(ShoppingReservation $reservation): void
    {
        $this->queue($reservation->user, new InPersonReservationConfirmed($reservation));

        $managers = User::where('role', User::ROLE_EMPLOYEE)->where('team', User::TEAM_SHOPPING)->get();
        if ($managers->isEmpty()) {
            Log::warning('In-person reservation confirmed but no shopping employee to notify', ['reservation' => $reservation->reservation_number]);
        }
        foreach ($managers as $manager) {
            $this->queue($manager, new InPersonReservationManagerAlert($reservation));
        }

        $this->expireOverlappingSessions($reservation);
    }

    /** Best effort: close the other open Checkout pages for hours this reservation just won. */
    private function expireOverlappingSessions(ShoppingReservation $winner): void
    {
        $others = ShoppingReservation::where('status', ShoppingReservation::PENDING)
            ->where('id', '!=', $winner->id)
            ->whereNotNull('stripe_checkout_session_id')
            ->where('starts_at', '<', $winner->endsAt())
            ->where('starts_at', '>', $winner->starts_at->copy()->subHours($this->maxHours()))
            ->get();
        foreach ($others as $other) {
            if ($other->starts_at->lt($winner->endsAt()) && $other->endsAt()->gt($winner->starts_at)) {
                try {
                    $this->stripe->expireCheckoutSession($other->stripe_checkout_session_id);
                } catch (\Throwable $e) {
                    Log::warning('Could not expire overlapping in-person checkout session', ['session' => $other->stripe_checkout_session_id, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    /** Late-webhook cover for the success page: still pending but Stripe says paid -> confirm now. */
    public function confirmIfPaid(ShoppingReservation $reservation): ShoppingReservation
    {
        if ($reservation->status !== ShoppingReservation::PENDING || ! $reservation->stripe_checkout_session_id) {
            return $reservation;
        }
        try {
            $session = $this->stripe->retrieveSession($reservation->stripe_checkout_session_id);
            if (($session->payment_status ?? null) === 'paid') {
                $this->confirm($reservation, $session->payment_intent ?? null, $session->invoice ?? null);
            }
        } catch (\Throwable $e) {
            Log::warning('In-person success-page session check failed', ['reservation' => $reservation->reservation_number, 'error' => $e->getMessage()]);
        }

        return $reservation->fresh(['user']);
    }

    /** Cancel a confirmed reservation and free its hours. Returns false if it was not confirmed. */
    public function cancel(ShoppingReservation $reservation, string $reason): bool
    {
        $done = DB::transaction(function () use ($reservation, $reason) {
            $flipped = ShoppingReservation::where('id', $reservation->id)->where('status', ShoppingReservation::CONFIRMED)
                ->update(['status' => ShoppingReservation::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            if ($flipped === 1) {
                ShoppingReservationSlot::where('reservation_id', $reservation->id)->update(['active_slot_id' => null]);
            }

            return $flipped === 1;
        });
        if ($done) {
            $this->queue($reservation->user, new InPersonReservationCancelled($reservation->fresh(['user'])));
        }

        return $done;
    }

    /** Record the visit and free the hours. Returns false if it was not confirmed. */
    public function complete(ShoppingReservation $reservation, float $hoursWorked, float $amountSpent): bool
    {
        return DB::transaction(function () use ($reservation, $hoursWorked, $amountSpent) {
            $flipped = ShoppingReservation::where('id', $reservation->id)->where('status', ShoppingReservation::CONFIRMED)
                ->update(['status' => ShoppingReservation::COMPLETED, 'hours_worked' => $hoursWorked, 'amount_spent_usd' => $amountSpent]);
            if ($flipped === 1) {
                ShoppingReservationSlot::where('reservation_id', $reservation->id)->update(['active_slot_id' => null]);
            }

            return $flipped === 1;
        });
    }

    /** Queue a mail; a mail failure only logs, it never fails the booking or the webhook. */
    private function queue(?User $to, $mailable): void
    {
        try {
            if ($to) {
                Mail::to($to)->queue($mailable);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to queue in-person reservation email', ['mail' => get_class($mailable), 'error' => $e->getMessage()]);
        }
    }
}
