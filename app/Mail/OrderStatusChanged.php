<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderStatusChanged extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Order $order,
        public string $previousStatus
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $locale = $this->order->user->preferred_language ?? 'es';
        
        $subjectKey = match($this->order->status) {
            Order::STATUS_AWAITING_PACKAGES => 'emails.order.status_changed.subject.awaiting_packages',
            Order::STATUS_PACKAGES_COMPLETE => 'emails.order.status_changed.subject.packages_complete',
            Order::STATUS_SHIPPED => 'emails.order.status_changed.subject.shipped',
            Order::STATUS_DELIVERED => 'emails.order.status_changed.subject.delivered',
            default => 'emails.order.status_changed.subject.default',
        };

        return new Envelope(
            subject: __($subjectKey, ['order_number' => $this->order->order_number], $locale),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.status-changed',
            with: [
                'statusLabel' => Order::getStatuses()[$this->order->status] ?? 'Unknown',
                'previousStatusLabel' => Order::getStatuses()[$this->previousStatus] ?? 'Unknown',
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