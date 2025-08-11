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
    ) {
        // Load relationships to avoid N+1 queries in the view
        $this->order->load(['user', 'items']);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $locale = $this->order->user->preferred_language ?? 'es';
        
        // Status-specific subject lines
        $subject = match($this->order->status) {
            Order::STATUS_COLLECTING => $locale === 'es' 
                ? "Orden {$this->order->tracking_number} - Lista para agregar productos"
                : "Order {$this->order->tracking_number} - Ready to add products",
                
            Order::STATUS_AWAITING_PACKAGES => $locale === 'es'
                ? "✅ Orden {$this->order->tracking_number} creada - Esperando paquetes"
                : "✅ Order {$this->order->tracking_number} created - Awaiting packages",
                
            Order::STATUS_PACKAGES_COMPLETE => $locale === 'es'
                ? "🎉 ¡Todos tus paquetes han llegado! - {$this->order->tracking_number}"
                : "🎉 All your packages have arrived! - {$this->order->tracking_number}",
                
            Order::STATUS_PROCESSING => $locale === 'es'
                ? "⚙️ Procesando tu orden - {$this->order->tracking_number}"
                : "⚙️ Processing your order - {$this->order->tracking_number}",
                
            Order::STATUS_QUOTE_SENT => $locale === 'es'
                ? "💰 Tu cotización está lista - {$this->order->tracking_number}"
                : "💰 Your quote is ready - {$this->order->tracking_number}",
                
            Order::STATUS_PAID => $locale === 'es'
                ? "✅ Pago recibido - {$this->order->tracking_number}"
                : "✅ Payment received - {$this->order->tracking_number}",
                
            Order::STATUS_SHIPPED => $locale === 'es'
                ? "🛫 Tu paquete está en camino - {$this->order->tracking_number}"
                : "🛫 Your package is on the way - {$this->order->tracking_number}",
                
            Order::STATUS_DELIVERED => $locale === 'es'
                ? "🎉 Paquete entregado - {$this->order->tracking_number}"
                : "🎉 Package delivered - {$this->order->tracking_number}",
                
            Order::STATUS_CANCELLED => $locale === 'es'
                ? "Orden cancelada - {$this->order->tracking_number}"
                : "Order cancelled - {$this->order->tracking_number}",
                
            default => $locale === 'es'
                ? "Actualización de orden - {$this->order->tracking_number}"
                : "Order update - {$this->order->tracking_number}",
        };

        return new Envelope(
            subject: $subject,
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
                'order' => $this->order,
                'previousStatus' => $this->previousStatus,
                'subject' => $this->envelope()->subject,
                'statusLabel' => $this->getStatusLabel($this->order->status),
                'previousStatusLabel' => $this->getStatusLabel($this->previousStatus),
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
     * Get localized status label
     */
    private function getStatusLabel(string $status): string
    {
        $locale = $this->order->user->preferred_language ?? 'es';
        
        $labels = [
            'es' => [
                Order::STATUS_COLLECTING => 'Agregando Artículos',
                Order::STATUS_AWAITING_PACKAGES => 'Esperando Paquetes',
                Order::STATUS_PACKAGES_COMPLETE => 'Paquetes Completos',
                Order::STATUS_PROCESSING => 'Procesando',
                Order::STATUS_QUOTE_SENT => 'Cotización Enviada',
                Order::STATUS_PAID => 'Pagado',
                Order::STATUS_SHIPPED => 'Enviado',
                Order::STATUS_DELIVERED => 'Entregado',
                Order::STATUS_CANCELLED => 'Cancelado',
            ],
            'en' => [
                Order::STATUS_COLLECTING => 'Adding Items',
                Order::STATUS_AWAITING_PACKAGES => 'Awaiting Packages',
                Order::STATUS_PACKAGES_COMPLETE => 'Packages Complete',
                Order::STATUS_PROCESSING => 'Processing',
                Order::STATUS_QUOTE_SENT => 'Quote Sent',
                Order::STATUS_PAID => 'Paid',
                Order::STATUS_SHIPPED => 'Shipped',
                Order::STATUS_DELIVERED => 'Delivered',
                Order::STATUS_CANCELLED => 'Cancelled',
            ],
        ];

        return $labels[$locale][$status] ?? $status;
    }

    /**
     * Determine if the email should be sent
     * Some status changes might not need notifications
     */
    public function shouldSend(): bool
    {
        // Don't send email for certain transitions
        $skipTransitions = [
            // Example: Don't send email when moving from cancelled to cancelled
            Order::STATUS_CANCELLED => [Order::STATUS_CANCELLED],
        ];

        if (isset($skipTransitions[$this->previousStatus])) {
            return !in_array($this->order->status, $skipTransitions[$this->previousStatus]);
        }

        return true;
    }
}