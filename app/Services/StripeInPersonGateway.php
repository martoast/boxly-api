<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

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

    public function createAndSendInvoice(string $customerId, array $invoiceParams, array $lines, string $idempotencyKey): object
    {
        $stripe = StripeAccount::shopping();
        $invoiceId = null;
        try {
            $invoice = $stripe->invoices->create($invoiceParams + [
                'customer' => $customerId,
                'currency' => 'usd',
                'collection_method' => 'send_invoice',
                'days_until_due' => 3,
                'auto_advance' => false,
            ], ['idempotency_key' => $idempotencyKey]);
            $invoiceId = $invoice->id;
            foreach ($lines as $line) {
                $stripe->invoiceItems->create([
                    'customer' => $customerId,
                    'invoice' => $invoice->id,
                    'currency' => 'usd',
                    'amount' => $line['amount'],
                    'description' => mb_substr($line['description'], 0, 250),
                ], ['idempotency_key' => $line['idempotency_key']]);
            }
            $stripe->invoices->finalizeInvoice($invoice->id);
            $sent = $stripe->invoices->sendInvoice($invoice->id);
        } catch (\Throwable $e) {
            throw new InPersonInvoiceFailed($invoiceId, $e);
        }

        return (object) ['id' => $invoice->id, 'hosted_invoice_url' => $sent->hosted_invoice_url];
    }

    public function discardInvoice(string $invoiceId): void
    {
        try {
            $stripe = StripeAccount::shopping();
            $status = $stripe->invoices->retrieve($invoiceId)->status;
            if ($status === 'draft') {
                $stripe->invoices->delete($invoiceId);
            } elseif ($status === 'open') {
                $stripe->invoices->voidInvoice($invoiceId);
            }
        } catch (\Throwable $e) {
            Log::error('In-person final invoice discard FAILED, void it in Stripe by hand', ['invoice' => $invoiceId, 'error' => $e->getMessage()]);
        }
    }

    public function refundPaymentIntent(string $paymentIntentId): void
    {
        StripeAccount::shopping()->refunds->create(['payment_intent' => $paymentIntentId]);
    }
}
