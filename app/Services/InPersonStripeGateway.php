<?php

namespace App\Services;

/** The few Stripe calls the in-person reservations need (shopping account); tests bind a fake. */
interface InPersonStripeGateway
{
    public function createCheckoutSession(array $params): object;

    public function expireCheckoutSession(string $sessionId): void;

    public function retrieveSession(string $sessionId): object;

    public function refundPaymentIntent(string $paymentIntentId): void;
}
