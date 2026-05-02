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

/**
 * Internal alert sent to the shopping team when a customer creates a new
 * Purchase Request (assisted-purchase flow). Tells Velonie there's
 * something new to review and quote.
 */
class PurchaseRequestCreatedTeamNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public PurchaseRequest $purchaseRequest) {}

    public function envelope(): Envelope
    {
        $pr = $this->purchaseRequest;
        $itemCount = $pr->items->count();

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: "📥 Nueva solicitud — {$pr->request_number} · {$itemCount} item" . ($itemCount === 1 ? '' : 's'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.purchase-requests.team-created',
            with: [
                'request'   => $this->purchaseRequest,
                'admin_url' => config('app.frontend_url') . '/app/shopping/purchase-requests/' . $this->purchaseRequest->id,
            ]
        );
    }
}
