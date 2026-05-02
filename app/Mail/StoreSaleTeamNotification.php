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
 * Internal alert sent to admin + shopping team whenever a Boxly Store
 * checkout completes payment. Lets Velonie act on the source-store
 * purchase quickly without having to babysit the dashboard.
 */
class StoreSaleTeamNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public PurchaseRequest $purchaseRequest) {}

    public function envelope(): Envelope
    {
        $pr = $this->purchaseRequest;
        $itemCount = $pr->items->count();

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: "💰 Nueva venta — {$pr->request_number} · {$itemCount} item" . ($itemCount === 1 ? '' : 's') . " · \${$pr->total_amount} MXN",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.store-sales.team-notification',
            with: [
                'request' => $this->purchaseRequest,
                'admin_url' => config('app.frontend_url') . '/app/shopping/purchase-requests/' . $this->purchaseRequest->id,
            ]
        );
    }
}
