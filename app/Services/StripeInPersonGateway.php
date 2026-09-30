<?php

namespace App\Services;

class StripeInPersonGateway implements InPersonStripeGateway
{
    public function createCheckoutSession(array $params): object
    {
        return StripeAccount::shopping()->checkout->sessions->create($params);
    }

    public function expireCheckoutSession(string $sessionId): void
    {
        StripeAccount::shopping()->checkout->sessions->expire($sessionId);
    }

    public function retrieveSession(string $sessionId): object
    {
        return StripeAccount::shopping()->checkout->sessions->retrieve($sessionId);
    }

    public function refundPaymentIntent(string $paymentIntentId): void
    {
        StripeAccount::shopping()->refunds->create(['payment_intent' => $paymentIntentId]);
    }
}
