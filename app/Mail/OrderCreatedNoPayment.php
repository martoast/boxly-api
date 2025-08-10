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

class OrderCreatedNoPayment extends Mailable implements ShouldQueue
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
            ? 'Orden creada exitosamente - ' . $this->order->order_number
            : 'Order created successfully - ' . $this->order->order_number;
        
        return new Envelope(
            from: new Address(
                config('mail.from.address', 'noreply@example.com'),
                config('mail.from.name', 'Envios Comerciales')
            ),
            replyTo: [
                new Address('envioscomercialestj@gmail.com', 'Envios Comerciales Support'),
            ],
            subject: $subject,
            // Move metadata here if using metadata for email service providers
            metadata: [
                'order_id' => (string) $this->order->id,
                'order_number' => $this->order->order_number,
                'user_id' => (string) $this->order->user_id,
                'user_email' => $this->order->user->email,
            ],
            tags: ['order-created', 'no-payment', 'order-' . $this->order->id],
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
            view: 'emails.orders.created-no-payment',
            with: [
                'order' => $this->order,
                'user' => $this->order->user,
                'locale' => $this->order->user->preferred_language ?? 'es',
                'warehouseAddress' => $this->getWarehouseAddress(),
                'frontendUrl' => config('app.frontend_url'),
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

    /**
     * Get the warehouse address for display
     * Updated to use User ID instead of tracking number
     *
     * @return array
     */
    private function getWarehouseAddress(): array
    {
        return [
            'name' => $this->order->user->name,
            'user_id' => $this->order->user->id,
            'reference' => 'ID: ' . $this->order->user->id, // Use User ID, not tracking number
            'street' => '2220 Otay Lakes Rd.',
            'suite' => 'Suite 502 #95',
            'city' => 'Chula Vista',
            'state' => 'CA',
            'zip' => '91915',
            'country' => 'United States',
            'phone' => '+1 (619) 559-1920',
        ];
    }
}