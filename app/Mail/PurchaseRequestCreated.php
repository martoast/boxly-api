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

class PurchaseRequestCreated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public PurchaseRequest $purchaseRequest
    ) {}

    public function envelope(): Envelope
    {
        $locale = $this->purchaseRequest->user->preferred_language ?? 'es';
        
        $subject = $locale === 'es' 
            ? '✅ Solicitud de Compra Recibida - ' . $this->purchaseRequest->request_number
            : '✅ Purchase Request Received - ' . $this->purchaseRequest->request_number;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase-requests.created',
            with: [
                'request' => $this->purchaseRequest,
                'user' => $this->purchaseRequest->user,
                'locale' => $this->purchaseRequest->user->preferred_language ?? 'es',
                'url' => config('app.frontend_url') . '/app/purchase-requests/' . $this->purchaseRequest->id,
            ]
        );
    }
}