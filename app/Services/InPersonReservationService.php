<?php

namespace App\Services;

use App\Mail\InPersonFinalInvoice;
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
    // US retail business hours (Alex 2026-10-02): no store opens before 9:00, and personal shopping ends ~2 h before
    // a late close — so hours run 9:00–18:00 California time.
    public const FIRST_HOUR = 9;
    public const LAST_HOUR = 17; // last slot starts at 17:00 and ends at 18:00

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
            $hour = (int) $local->format('G');
            // Hours published before the 9–18 window existed are never offered (nor booked: hoursAvailable reads this).
            if ($hour < self::FIRST_HOUR || $hour > self::LAST_HOUR) {
                continue;
            }
            $byDate[$local->toDateString()][$hour] = $slot->id;
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
                                'Reserva de compra personal — San Diego — %s %s (primera hora; total reservado: %d h)',
                                $date, $reservation->startLabel(), $hours,
                            ),
                        ],
                    ],
                ]],
                'payment_method_types' => ['card'],
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

        $this->confirm($reservation, $session->payment_intent ?? null, $session->invoice ?? null, $session->id ?? null);
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
    public function confirm(ShoppingReservation $reservation, ?string $paymentIntent, ?string $invoice, ?string $sessionId = null): void
    {
        if ($sessionId && $reservation->stripe_checkout_session_id && $sessionId !== $reservation->stripe_checkout_session_id) {
            Log::warning('In-person confirm ignored: checkout session does not belong to the reservation', [
                'reservation' => $reservation->reservation_number, 'session' => $sessionId, 'expected' => $reservation->stripe_checkout_session_id,
            ]);

            return;
        }

        $confirmed = false;
        $reason = ShoppingReservation::REASON_PAID_FIRST;
        try {
            DB::transaction(function () use ($reservation, $paymentIntent, $invoice, &$confirmed, &$reason) {
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
                $slots = ShoppingSlot::where('location', ShoppingSlot::LOCATION)->whereIn('starts_at', $times)->orderBy('starts_at')->get();
                if ($slots->count() !== count($times)) {
                    $reason = ShoppingReservation::REASON_HOUR_UNAVAILABLE;
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
            $this->slotTaken($reservation, $paymentIntent, $e instanceof \DomainException ? ShoppingReservation::REASON_HOUR_UNAVAILABLE : ShoppingReservation::REASON_PAID_FIRST);

            return;
        }

        if ($confirmed) {
            $this->afterConfirmed($reservation->fresh(['user']));
        }
    }

    private function slotTaken(ShoppingReservation $reservation, ?string $paymentIntent, string $reason): void
    {
        $flipped = ShoppingReservation::where('id', $reservation->id)
            ->where('status', ShoppingReservation::PENDING)
            ->update(['status' => ShoppingReservation::SLOT_TAKEN, 'slot_taken_reason' => $reason, 'paid_at' => now(), 'stripe_payment_intent_id' => $paymentIntent]);
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
                $this->confirm($reservation, $session->payment_intent ?? null, $session->invoice ?? null, $reservation->stripe_checkout_session_id);
            }
        } catch (\Throwable $e) {
            Log::warning('In-person success-page session check failed', ['reservation' => $reservation->reservation_number, 'error' => $e->getMessage()]);
        }

        return $reservation->fresh(['user']);
    }

    /** Reservations the team still has to refund by hand (failed slot_taken refund, or cancelled after paying). */
    public function pendingRefunds()
    {
        return ShoppingReservation::with('user')->whereNull('refunded_at')->whereNull('refund_waived_at')
            ->where(fn ($q) => $q->where('status', ShoppingReservation::SLOT_TAKEN)
                ->orWhere(fn ($q) => $q->where('status', ShoppingReservation::CANCELLED)->whereNotNull('paid_at')))
            ->orderByDesc('starts_at')->orderByDesc('id')->get();
    }

    /** Stamp refunded_at on a refund-needing reservation. Idempotent; false when it is not that kind. */
    public function markRefunded(ShoppingReservation $reservation): bool
    {
        if (! $reservation->needsRefund()) {
            return false;
        }
        if ($reservation->refunded_at === null) {
            $reservation->update(['refunded_at' => now()]);
        }

        return true;
    }

    /** Team decides no refund is owed. Idempotent; false unless the reservation needs (or already had waived) a refund. */
    public function waiveRefund(ShoppingReservation $reservation): bool
    {
        if ($reservation->refund_waived_at !== null) {
            return true;
        }
        if (! $reservation->refundPending()) {
            return false;
        }
        ShoppingReservation::where('id', $reservation->id)->whereNull('refund_waived_at')->whereNull('refunded_at')
            ->update(['refund_waived_at' => now()]);

        return true;
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
                ->update(['status' => ShoppingReservation::COMPLETED, 'completed_at' => now(), 'hours_worked' => $hoursWorked, 'amount_spent_usd' => $amountSpent]);
            if ($flipped === 1) {
                ShoppingReservationSlot::where('reservation_id', $reservation->id)->update(['active_slot_id' => null]);
            }

            return $flipped === 1;
        });
    }

    /**
     * Bill the visit: hours + commission - what was paid up front. A zero/negative total is
     * settled without an invoice. Returns null when done, else the reason it was refused
     * (422 for the caller). The row is claimed atomically FIRST so only one caller can ever create
     * the Stripe invoice. Throws on a Stripe failure (claim released, draft discarded, nothing
     * stored) or InPersonInvoiceSentButUnrecorded when the invoice was sent but could not be saved
     * (claim kept so nobody sends a second one).
     */
    public function createFinalInvoice(ShoppingReservation $reservation): ?string
    {
        $final = $reservation->final();
        if (! $final) {
            return 'Solo se puede facturar una reserva completada con horas y gasto registrados';
        }
        $taken = 'Esta factura final ya se está generando o ya fue enviada';
        $claimable = fn () => ShoppingReservation::where('id', $reservation->id)->where('status', ShoppingReservation::COMPLETED)
            ->whereNull('final_invoice_id')->whereNull('final_paid_at')->whereNull('final_invoice_claimed_at');

        if ($final['total_usd'] <= 0) {
            $now = now();
            $won = $claimable()->update(['final_invoice_claimed_at' => $now, 'final_paid_at' => $now, 'final_amount_usd' => 0]);

            return $won === 1 ? null : $taken;
        }

        $claimedAt = now();
        if ($claimable()->update(['final_invoice_claimed_at' => $claimedAt]) !== 1) {
            return $taken;
        }

        $cents = fn (float $usd) => (int) round($usd * 100);
        $pct = rtrim(rtrim(number_format($final['commission_percent'], 1, '.', ''), '0'), '.');
        $lines = [
            ['key' => 'hours', 'description' => sprintf('Compra personal: %s h trabajadas / hours worked', $final['hours_worked'] + 0), 'amount' => $cents($final['hours_fee_usd'])],
            ['key' => 'commission', 'description' => sprintf('Comisión Boxly / Boxly commission (%s%% de $%.2f)', $pct, $final['amount_spent_usd']), 'amount' => $cents($final['commission_usd'])],
            ['key' => 'credit', 'description' => 'Reserva pagada / Reservation already paid', 'amount' => -$cents($final['credit_usd'])],
        ];
        $lines = array_values(array_filter($lines, fn ($l) => $l['amount'] !== 0));

        // Stripe keeps an idempotency key's response for 24 h, including for an invoice we voided after a
        // failure; the claim time makes each claimed attempt a fresh key while still de-duping network retries.
        $key = 'in-person-final-' . $reservation->id . '-' . $claimedAt->timestamp;
        foreach ($lines as &$line) {
            $line['idempotency_key'] = $key . '-' . $line['key'];
        }
        unset($line);

        try {
            $reservation->loadMissing('user');
            $invoice = $this->stripe->createAndSendInvoice($reservation->user->stripeShoppingCustomerId(), [
                'description' => 'Compra personal San Diego ' . $reservation->reservation_number,
                'metadata' => ['type' => 'in_person_final_invoice', 'reservation_id' => (string) $reservation->id, 'reservation_number' => $reservation->reservation_number],
            ], $lines, $key);
        } catch (\Throwable $e) {
            if ($e instanceof InPersonInvoiceFailed && $e->invoiceId) {
                $this->stripe->discardInvoice($e->invoiceId);
            }
            ShoppingReservation::where('id', $reservation->id)->whereNull('final_invoice_id')->update(['final_invoice_claimed_at' => null]);

            throw $e;
        }

        $saved = false;
        for ($try = 1; $try <= 3 && ! $saved; $try++) {
            try {
                $reservation->update([
                    'final_invoice_id' => $invoice->id,
                    'final_invoice_url' => $invoice->hosted_invoice_url,
                    'final_amount_usd' => $final['total_usd'],
                    'final_invoice_sent_at' => now(),
                ]);
                $saved = true;
            } catch (\Throwable $e) {
                Log::warning('In-person final invoice sent but saving it failed', ['reservation' => $reservation->reservation_number, 'invoice' => $invoice->id, 'try' => $try, 'error' => $e->getMessage()]);
            }
        }
        if (! $saved) {
            Log::critical('In-person final invoice SENT to the customer but NOT recorded; claim kept, do not regenerate', ['reservation' => $reservation->reservation_number, 'reservation_id' => $reservation->id, 'stripe_invoice_id' => $invoice->id]);

            throw new InPersonInvoiceSentButUnrecorded($invoice->id);
        }
        $this->queue($reservation->user, new InPersonFinalInvoice($reservation->fresh(['user'])));

        return null;
    }

    /**
     * invoice.paid for a final invoice (signed webhook, found by the reservation id in its metadata): idempotent.
     * A row that never recorded the invoice (sent but unrecorded) gets its id/url stored together with the paid stamp;
     * a different stored invoice id is logged and ignored.
     */
    public function markFinalPaid(string $reservationId, ?string $invoiceId = null, ?string $invoiceUrl = null): void
    {
        $reservation = ShoppingReservation::find($reservationId);
        if (! $reservation || $reservation->final_paid_at !== null) {
            return;
        }
        if ($reservation->final_invoice_id === null) {
            if (! $invoiceId) {
                return;
            }
            $update = ['final_invoice_id' => $invoiceId, 'final_paid_at' => now()];
            if ($invoiceUrl) {
                $update['final_invoice_url'] = $invoiceUrl;
            }
            ShoppingReservation::where('id', $reservation->id)->whereNull('final_invoice_id')->whereNull('final_paid_at')->update($update);

            return;
        }
        if ($invoiceId && $reservation->final_invoice_id !== $invoiceId) {
            Log::warning('In-person final invoice.paid ignored: invoice does not match the stored one', [
                'reservation' => $reservation->reservation_number, 'stored' => $reservation->final_invoice_id, 'paid' => $invoiceId,
            ]);

            return;
        }
        ShoppingReservation::where('id', $reservation->id)->whereNotNull('final_invoice_id')->whereNull('final_paid_at')
            ->update(['final_paid_at' => now()]);
    }

    /**
     * Unstick a final invoice whose claim was taken but never recorded (worker died). Returns 'released' (the team
     * may generate again), 'recovered' (Stripe already had the sent invoice; now recorded) or null when the
     * reservation is not stuck. Never creates an invoice. Throws when Stripe cannot be asked: the claim is kept,
     * because a new claim would get a new idempotency key and could double-invoice.
     */
    public function retryFinalInvoice(ShoppingReservation $reservation): ?string
    {
        if (! $reservation->finalInvoiceStuck()) {
            return null;
        }
        $invoice = $this->stripe->findInvoiceByReservation($reservation->id);
        if ($invoice) {
            $paid = ($invoice->status ?? null) === 'paid';
            $amount = isset($invoice->amount_due) ? round($invoice->amount_due / 100, 2) : ($reservation->final()['total_usd'] ?? null);
            $recorded = ShoppingReservation::where('id', $reservation->id)->whereNull('final_invoice_id')->whereNull('final_paid_at')
                ->update([
                    'final_invoice_id' => $invoice->id,
                    'final_invoice_url' => $invoice->hosted_invoice_url ?? null,
                    'final_amount_usd' => $amount,
                    'final_invoice_sent_at' => now(),
                    'final_paid_at' => $paid ? now() : null,
                ]);
            if ($recorded === 1 && ! $paid) {
                $this->queue($reservation->user, new InPersonFinalInvoice($reservation->fresh(['user'])));
            }

            return 'recovered';
        }
        $released = ShoppingReservation::where('id', $reservation->id)->whereNull('final_invoice_id')->whereNull('final_paid_at')
            ->where('final_invoice_claimed_at', '<=', now()->subMinutes(ShoppingReservation::STUCK_CLAIM_MINUTES))
            ->update(['final_invoice_claimed_at' => null]);

        return $released === 1 ? 'released' : null;
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
