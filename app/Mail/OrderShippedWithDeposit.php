<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment; // Import Attachment
use Illuminate\Queue\SerializesModels;

class OrderShippedWithDeposit extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {}

    public function envelope(): Envelope
    {
        $locale = $this->order->user->preferred_language ?? 'es';
        $subject = $locale === 'es' 
            ? '📦 Orden Enviada - Guía y Depósito - ' . $this->order->tracking_number
            : '📦 Order Shipped - Tracking and Deposit - ' . $this->order->tracking_number;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $cleanGuia = str_replace(' ', '', $this->order->guia_number);
        $trackingLink = config('app.frontend_url') . '/track?tracking_number=' . $cleanGuia;

        return new Content(
            view: 'emails.orders.shipped-with-deposit',
            with: [
                'order' => $this->order,
                'user' => $this->order->user,
                'locale' => $this->order->user->preferred_language ?? 'es',
                'trackingLink' => $trackingLink,
                'depositLink' => $this->order->deposit_payment_link,
                // We still pass the URL as a backup in case they can't open the attachment
                'giaUrl' => $this->order->gia_full_url, 
            ]
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        // If for some reason the path is missing, return empty array
        if (! $this->order->gia_path) {
            return [];
        }

        // This pulls the file directly from your 'spaces' disk
        return [
            Attachment::fromStorageDisk('spaces', $this->order->gia_path)
                ->as($this->order->gia_filename ?? 'guia.pdf')
                ->withMime($this->order->gia_mime_type ?? 'application/pdf'),
        ];
    }
}