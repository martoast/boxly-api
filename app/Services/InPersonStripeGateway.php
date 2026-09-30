<?php

namespace App\Services;

/** The few Stripe calls the in-person reservations need (shopping account); tests bind a fake. */
interface InPersonStripeGateway
{
    public function createCheckoutSession(array $params): object;

    public function expireCheckoutSession(string $sessionId): void;

    public function retrieveSession(string $sessionId): object;

    public function refundPaymentIntent(string $paymentIntentId): void;

    /**
     * Create, finalize and send a send_invoice invoice with the given lines
     * (description + amount in cents + idempotency_key, amount may be negative) using $idempotencyKey for the
     * invoice itself. Returns an object with id and hosted_invoice_url. A failure before the invoice was sent
     * must throw InPersonInvoiceFailed carrying the Stripe invoice id (when one exists) so the caller can discard it.
     */
    public function createAndSendInvoice(string $customerId, array $invoiceParams, array $lines, string $idempotencyKey): object;

    /**
     * The sent (open or paid) final invoice of a reservation, found by its metadata, or null when none exists.
     * Returns an object with id, hosted_invoice_url, status and amount_due (cents). Throws when Stripe cannot be asked.
     */
    public function findInvoiceByReservation(int $reservationId): ?object;

    /** Delete a draft invoice / void a finalized one. Failures are logged, never thrown. */
    public function discardInvoice(string $invoiceId): void;
}
