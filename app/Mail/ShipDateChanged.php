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
use Illuminate\Support\Carbon;

class ShipDateChanged extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {}

    public function envelope(): Envelope
    {
        $locale = $this->order->user->preferred_language ?? 'es';

        $subject = $locale === 'es'
            ? '📅 Nueva fecha de envío - ' . $this->order->order_number
            : '📅 Updated ship date - ' . $this->order->order_number;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $locale = $this->order->user->preferred_language ?? 'es';

        $shipLabel = $this->order->planned_ship_date
            ? Carbon::parse($this->order->planned_ship_date)->locale($locale)
                ->translatedFormat($locale === 'es' ? 'j \d\e F \d\e Y' : 'F j, Y')
            : '';

        return new Content(
            view: 'emails.orders.ship-date-changed',
            with: [
                'order' => $this->order,
                'user' => $this->order->user,
                'locale' => $locale,
                'shipLabel' => $shipLabel,
            ]
        );
    }
}
