<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\PurchaseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Address;

class PurchaseRequestItemsPurchased extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public PurchaseRequest $purchaseRequest,
        public Order $order
    ) {}

    public function envelope(): Envelope
    {
        $locale = $this->purchaseRequest->user->preferred_language ?? 'es';
        
        $subject = $locale === 'es' 
            ? '✅ ¡Artículos Comprados! - Solicitud ' . $this->purchaseRequest->request_number
            : '✅ Items Purchased! - Request ' . $this->purchaseRequest->request_number;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase-requests.items-purchased',
            with: [
                'request' => $this->purchaseRequest,
                'order' => $this->order,
                'user' => $this->purchaseRequest->user,
                'locale' => $this->purchaseRequest->user->preferred_language ?? 'es',
                'url' => config('app.frontend_url') . '/app/orders/' . $this->order->id,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}