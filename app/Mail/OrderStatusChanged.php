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

    public function __construct(
        public Order $order,
        public string $previousStatus
    ) {
        $this->order->load(['user', 'items']);
    }

    public function envelope(): Envelope
    {
        $locale = $this->order->user->preferred_language ?? 'es';

        $subject = match ($this->order->status) {
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

            Order::STATUS_SHIPPED => $locale === 'es'
                ? "📦 Tu paquete ha sido enviado - {$this->order->tracking_number}"
                : "📦 Your package has been shipped - {$this->order->tracking_number}",

            Order::STATUS_DELIVERED => $locale === 'es'
                ? "🎉 ¡Paquete entregado! - {$this->order->tracking_number}"
                : "🎉 Package delivered! - {$this->order->tracking_number}",

            Order::STATUS_AWAITING_PAYMENT => $locale === 'es'
                ? "💰 Factura lista para pago - {$this->order->tracking_number}"
                : "💰 Invoice ready for payment - {$this->order->tracking_number}",

            Order::STATUS_PAID => $locale === 'es'
                ? "✅ ¡Pago recibido! Gracias - {$this->order->tracking_number}"
                : "✅ Payment received! Thank you - {$this->order->tracking_number}",

            Order::STATUS_CANCELLED => $locale === 'es'
                ? "❌ Orden cancelada - {$this->order->tracking_number}"
                : "❌ Order cancelled - {$this->order->tracking_number}",

            default => $locale === 'es'
                ? "📬 Actualización de orden - {$this->order->tracking_number}"
                : "📬 Order update - {$this->order->tracking_number}",
        };

        return new Envelope(
            subject: $subject,
        );
    }

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
                'statusMessage' => $this->getStatusMessage(),
                'locale' => $this->order->user->preferred_language ?? 'es',
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }

    private function getStatusLabel(string $status): string
    {
        $locale = $this->order->user->preferred_language ?? 'es';

        $labels = [
            'es' => [
                Order::STATUS_COLLECTING => 'Agregando Artículos',
                Order::STATUS_AWAITING_PACKAGES => 'Esperando Paquetes',
                Order::STATUS_PACKAGES_COMPLETE => 'Paquetes Completos',
                Order::STATUS_PROCESSING => 'Procesando',
                Order::STATUS_SHIPPED => 'Enviado',
                Order::STATUS_DELIVERED => 'Entregado',
                Order::STATUS_AWAITING_PAYMENT => 'Esperando Pago',
                Order::STATUS_PAID => 'Pagado',
                Order::STATUS_CANCELLED => 'Cancelado',
            ],
            'en' => [
                Order::STATUS_COLLECTING => 'Adding Items',
                Order::STATUS_AWAITING_PACKAGES => 'Awaiting Packages',
                Order::STATUS_PACKAGES_COMPLETE => 'Packages Complete',
                Order::STATUS_PROCESSING => 'Processing',
                Order::STATUS_SHIPPED => 'Shipped',
                Order::STATUS_DELIVERED => 'Delivered',
                Order::STATUS_AWAITING_PAYMENT => 'Awaiting Payment',
                Order::STATUS_PAID => 'Paid',
                Order::STATUS_CANCELLED => 'Cancelled',
            ],
        ];

        return $labels[$locale][$status] ?? $status;
    }

    /**
     * Determine if the email should be sent
     * This prevents duplicate or unnecessary notifications
     */
    public function shouldSend(): bool
    {
        // Don't send if previous status is the same as current (no actual change)
        if ($this->previousStatus === $this->order->status) {
            return false;
        }

        // Skip certain transitions that shouldn't trigger emails
        $skipTransitions = [
            // Don't send email if already cancelled and staying cancelled
            Order::STATUS_CANCELLED => [Order::STATUS_CANCELLED],
        ];

        if (isset($skipTransitions[$this->previousStatus])) {
            return !in_array($this->order->status, $skipTransitions[$this->previousStatus]);
        }

        return true;
    }

    /**
     * Get a message description for the status change
     */
    private function getStatusMessage(): string
    {
        $locale = $this->order->user->preferred_language ?? 'es';

        $messages = [
            'es' => [
                Order::STATUS_COLLECTING => 'Tu orden ha sido creada. Puedes comenzar a agregar artículos.',
                Order::STATUS_AWAITING_PACKAGES => 'Tu orden ha sido enviada. Esperamos la llegada de tus paquetes a nuestro almacén.',
                Order::STATUS_PACKAGES_COMPLETE => '¡Todos tus paquetes han llegado! Ahora comenzaremos a procesar tu orden.',
                Order::STATUS_PROCESSING => 'Estamos consolidando y preparando tu envío.',
                Order::STATUS_SHIPPED => 'Tu paquete ha sido enviado y está en camino a tu dirección.',
                Order::STATUS_DELIVERED => '¡Tu paquete ha sido entregado exitosamente!',
                Order::STATUS_AWAITING_PAYMENT => 'Tu orden ha sido entregada. La factura está lista para tu pago.',
                Order::STATUS_PAID => '¡Gracias por tu pago! Tu orden está completa.',
                Order::STATUS_CANCELLED => 'Esta orden ha sido cancelada.',
            ],
            'en' => [
                Order::STATUS_COLLECTING => 'Your order has been created. You can start adding items.',
                Order::STATUS_AWAITING_PACKAGES => 'Your order has been submitted. We are waiting for your packages to arrive at our warehouse.',
                Order::STATUS_PACKAGES_COMPLETE => 'All your packages have arrived! We will now start processing your order.',
                Order::STATUS_PROCESSING => 'We are consolidating and preparing your shipment.',
                Order::STATUS_SHIPPED => 'Your package has been shipped and is on its way to your address.',
                Order::STATUS_DELIVERED => 'Your package has been successfully delivered!',
                Order::STATUS_AWAITING_PAYMENT => 'Your order has been delivered. The invoice is ready for your payment.',
                Order::STATUS_PAID => 'Thank you for your payment! Your order is complete.',
                Order::STATUS_CANCELLED => 'This order has been cancelled.',
            ],
        ];

        return $messages[$locale][$this->order->status] ?? '';
    }
}
