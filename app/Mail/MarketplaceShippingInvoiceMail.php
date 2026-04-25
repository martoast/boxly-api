<?php

namespace App\Mail;

use App\Models\MarketplaceOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

class MarketplaceShippingInvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public MarketplaceOrder $order
    ) {}

    public function envelope(): Envelope
    {
        $locale = $this->order->user->preferred_language ?? 'es';
        $subject = $locale === 'es'
            ? '📦 Pago de envío listo - ' . $this->order->order_number
            : '📦 Shipping invoice ready - ' . $this->order->order_number;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.marketplace.shipping-invoice',
            with: [
                'order' => $this->order,
                'user' => $this->order->user,
                'locale' => $this->order->user->preferred_language ?? 'es',
                'orderUrl' => config('app.frontend_url') . '/app/marketplace-orders/' . $this->order->id,
            ]
        );
    }
}
