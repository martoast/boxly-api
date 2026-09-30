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
     * (description + amount in cents, may be negative). Returns an object with id and hosted_invoice_url.
     */
    public function createAndSendInvoice(string $customerId, array $invoiceParams, array $lines): object;
}
