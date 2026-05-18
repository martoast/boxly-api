<?php

namespace App\Mail;

use App\Models\PurchaseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PurchaseRequestInPersonScheduled extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public PurchaseRequest $purchaseRequest
    ) {}

    public function envelope(): Envelope
    {
        $locale = $this->purchaseRequest->user->preferred_language ?? 'es';

        $subject = $locale === 'es'
            ? '🛍️ Visita agendada — ' . $this->purchaseRequest->request_number
            : '🛍️ Trip scheduled — ' . $this->purchaseRequest->request_number;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $this->purchaseRequest->loadMissing(['items', 'shoppingTrip', 'stores', 'user']);

        return new Content(
            view: 'emails.purchase-requests.in-person-scheduled',
            with: [
                'request'       => $this->purchaseRequest,
                'user'          => $this->purchaseRequest->user,
                'trip'          => $this->purchaseRequest->shoppingTrip,
                'storeBreakdown'=> $this->purchaseRequest->inPersonStoreBreakdown(),
                'locale'        => $this->purchaseRequest->user->preferred_language ?? 'es',
                'url'           => config('app.frontend_url') . '/app/purchase-requests/' . $this->purchaseRequest->id,
            ],
        );
    }
}
