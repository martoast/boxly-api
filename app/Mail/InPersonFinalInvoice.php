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

class InPersonFinalInvoice extends Mailable implements ShouldQueue
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
            subject: $this->lang() === 'es' ? 'Tu factura final — ' . $r->reservation_number : 'Your final invoice — ' . $r->reservation_number,
        );
    }

    public function content(): Content
    {
        $this->reservation->loadMissing('user');
        $front = config('app.frontend_url');

        return new Content(
            view: 'emails.in-person.final-invoice',
            with: [
                'r' => $this->reservation,
                'user' => $this->reservation->user,
                'locale' => $this->lang(),
                'whatsapp' => 'https://wa.me/' . config('services.in_person.whatsapp'),
                'url' => $this->reservation->final_invoice_url ?: $front . '/in-person',
                'final' => $this->reservation->final(),
            ],
        );
    }
}
