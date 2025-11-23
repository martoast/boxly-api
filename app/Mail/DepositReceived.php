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

class DepositReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {}

    public function envelope(): Envelope
    {
        $locale = $this->order->user->preferred_language ?? 'es';
        $subject = $locale === 'es' 
            ? '✅ Depósito Recibido - Orden ' . $this->order->tracking_number
            : '✅ Deposit Received - Order ' . $this->order->tracking_number;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        // Clean the guia number for the URL parameter
        $cleanGuia = str_replace(' ', '', $this->order->guia_number);
        $trackingLink = config('app.frontend_url') . '/track?tracking_number=' . $cleanGuia;

        return new Content(
            view: 'emails.orders.deposit-received',
            with: [
                'order' => $this->order,
                'user' => $this->order->user,
                'locale' => $this->order->user->preferred_language ?? 'es',
                'trackingLink' => $trackingLink,
                'depositAmount' => $this->order->deposit_amount,
                'currency' => $this->order->currency,
            ]
        );
    }
}