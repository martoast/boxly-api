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

    public function createAndSendInvoice(string $customerId, array $invoiceParams, array $lines): object
    {
        $stripe = StripeAccount::shopping();
        $invoice = $stripe->invoices->create($invoiceParams + [
            'customer' => $customerId,
            'currency' => 'usd',
            'collection_method' => 'send_invoice',
            'days_until_due' => 3,
            'auto_advance' => false,
        ]);
        foreach ($lines as $line) {
            $stripe->invoiceItems->create([
                'customer' => $customerId,
                'invoice' => $invoice->id,
                'currency' => 'usd',
                'amount' => $line['amount'],
                'description' => mb_substr($line['description'], 0, 250),
            ]);
        }
        $stripe->invoices->finalizeInvoice($invoice->id);
        $sent = $stripe->invoices->sendInvoice($invoice->id);

        return (object) ['id' => $invoice->id, 'hosted_invoice_url' => $sent->hosted_invoice_url];
    }

    public function refundPaymentIntent(string $paymentIntentId): void
    {
        StripeAccount::shopping()->refunds->create(['payment_intent' => $paymentIntentId]);
    }
}
