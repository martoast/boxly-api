<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

class AllPackagesArrived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {}

    public function envelope(): Envelope
    {
        $locale = $this->order->user->preferred_language ?? 'es';

        $subject = $locale === 'es'
            ? '📦 Todos tus paquetes han llegado - ' . $this->order->order_number
            : '📦 All your packages have arrived - ' . $this->order->order_number;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $locale = $this->order->user->preferred_language ?? 'es';
        $items = $this->order->items;

        return new Content(
            view: 'emails.orders.all-packages-arrived',
            with: [
                'order' => $this->order,
                'user' => $this->order->user,
                'locale' => $locale,
                'items' => $items,
                'arrivalImageUrl' => $this->order->arrival_image_full_url,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
