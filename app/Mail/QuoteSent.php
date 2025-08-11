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

class QuoteSent extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The order instance.
     *
     * @var \App\Models\Order
     */
    public Order $order;

    /**
     * Create a new message instance.
     *
     * @param  \App\Models\Order  $order
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Get the message envelope.
     *
     * @return \Illuminate\Mail\Mailables\Envelope
     */
    public function envelope(): Envelope
    {
        $locale = $this->order->user->preferred_language ?? 'es';
        
        $subject = $locale === 'es' 
            ? '💰 Tu cotización está lista - Orden ' . $this->order->order_number
            : '💰 Your quote is ready - Order ' . $this->order->order_number;
        
        return new Envelope(
            from: new Address(
                config('mail.from.address', 'noreply@example.com'),
                config('mail.from.name', 'Envios Comerciales')
            ),
            replyTo: [
                new Address('envioscomercialestj@gmail.com', 'Envios Comerciales Support'),
            ],
            subject: $subject,
            metadata: [
                'order_id' => (string) $this->order->id,
                'order_number' => $this->order->order_number,
                'user_id' => (string) $this->order->user_id,
                'user_email' => $this->order->user->email,
                'quoted_amount' => (string) $this->order->quoted_amount,
            ],
            tags: ['quote-sent', 'order-' . $this->order->id],
        );
    }

    /**
     * Get the message content definition.
     *
     * @return \Illuminate\Mail\Mailables\Content
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.quote-sent',
            with: [
                'order' => $this->order,
                'user' => $this->order->user,
                'locale' => $this->order->user->preferred_language ?? 'es',
                'items' => $this->order->items,
                'quoteBreakdown' => $this->order->quote_breakdown,
                'quotedAmount' => $this->order->quoted_amount,
                'paymentLink' => $this->order->payment_link,
                'quoteExpiresAt' => $this->order->quote_expires_at,
                'frontendUrl' => config('app.frontend_url'),
                'supportEmail' => 'envioscomercialestj@gmail.com',
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}