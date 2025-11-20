<?php

namespace App\Mail;

use App\Models\PurchaseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

class PurchaseRequestPaymentReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public PurchaseRequest $purchaseRequest
    ) {}

    public function envelope(): Envelope
    {
        $locale = $this->purchaseRequest->user->preferred_language ?? 'es';
        
        $subject = $locale === 'es' 
            ? '✅ Pago Recibido - Solicitud ' . $this->purchaseRequest->request_number
            : '✅ Payment Received - Request ' . $this->purchaseRequest->request_number;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase-requests.payment-received',
            with: [
                'request' => $this->purchaseRequest,
                'user' => $this->purchaseRequest->user,
                'locale' => $this->purchaseRequest->user->preferred_language ?? 'es',
            ]
        );
    }
}