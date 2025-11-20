<?php

namespace App\Mail;

use App\Models\PurchaseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Address;

class PurchaseRequestQuoteSent extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public PurchaseRequest $purchaseRequest
    ) {}

    public function envelope(): Envelope
    {
        $locale = $this->purchaseRequest->user->preferred_language ?? 'es';
        
        $subject = $locale === 'es' 
            ? '💰 Cotización lista para tu Compra Asistida - ' . $this->purchaseRequest->request_number
            : '💰 Quote ready for your Assisted Purchase - ' . $this->purchaseRequest->request_number;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase-requests.quote-sent',
            with: [
                'request' => $this->purchaseRequest,
                'user' => $this->purchaseRequest->user,
                'locale' => $this->purchaseRequest->user->preferred_language ?? 'es',
                'url' => $this->purchaseRequest->payment_link,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}