<?php

namespace App\Mail;

use App\Models\ShoppingTripBooking;
use App\Models\Store;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShoppingTripBookingConfirmed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ShoppingTripBooking $booking)
    {}

    public function envelope(): Envelope
    {
        $locale = $this->booking->user->preferred_language ?? 'es';
        $subject = $locale === 'es'
            ? '✅ Tu visita está reservada — ' . $this->booking->booking_number
            : '✅ Your trip is booked — ' . $this->booking->booking_number;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $this->booking->loadMissing(['user', 'shoppingTrip']);

        $stores = Store::whereIn('id', $this->booking->store_ids ?? [])
            ->pluck('name')
            ->all();

        return new Content(
            view: 'emails.shopping-trips.booking-confirmed',
            with: [
                'booking' => $this->booking,
                'user'    => $this->booking->user,
                'trip'    => $this->booking->shoppingTrip,
                'stores'  => $stores,
                'deposit' => $this->booking->deposit_amount_usd,
                'locale'  => $this->booking->user->preferred_language ?? 'es',
                'url'     => rtrim(config('app.frontend_url'), '/') . '/app',
            ],
        );
    }
}
