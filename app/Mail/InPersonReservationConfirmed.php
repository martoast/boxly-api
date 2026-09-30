<?php

namespace App\Mail;

use App\Models\ShoppingReservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InPersonReservationConfirmed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ShoppingReservation $reservation) {}

    private function lang(): string
    {
        return $this->reservation->user->preferred_language ?? 'es';
    }

    public function envelope(): Envelope
    {
        $r = $this->reservation;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $this->lang() === 'es' ? '✅ Reservaste tu compra personal — ' . $r->dateLabel('es') . ' · ' . $r->startLabel() : '✅ Your personal shopping is booked — ' . $r->dateLabel('en') . ' · ' . $r->startLabel(),
        );
    }

    public function content(): Content
    {
        $this->reservation->loadMissing('user');
        $front = config('app.frontend_url');

        return new Content(
            view: 'emails.in-person.confirmed',
            with: [
                'r' => $this->reservation,
                'user' => $this->reservation->user,
                'locale' => $this->lang(),
                'whatsapp' => 'https://wa.me/' . config('services.in_person.whatsapp'),
                'url' => $front . '/in-person/success?ref=' . urlencode($this->reservation->reservation_number),
            ],
        );
    }
}
