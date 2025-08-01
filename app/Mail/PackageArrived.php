<?php

namespace App\Mail;

use App\Models\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PackageArrived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public OrderItem $item
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $locale = $this->item->order->user->preferred_language ?? 'es';
        
        return new Envelope(
            subject: __('emails.order.package_arrived.subject', [
                'product_name' => $this->item->product_name
            ], $locale),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.package-arrived',
            with: [
                'order' => $this->item->order,
                'arrivedCount' => $this->item->order->arrivedItems()->count(),
                'totalCount' => $this->item->order->items()->count(),
                'arrivalProgress' => $this->item->order->arrival_progress,
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