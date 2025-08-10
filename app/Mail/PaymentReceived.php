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
use Illuminate\Support\Carbon;

class PaymentReceived extends Mailable implements ShouldQueue
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
            ? '✅ Pago recibido - Orden ' . $this->order->order_number
            : '✅ Payment received - Order ' . $this->order->order_number;
        
        return new Envelope(
            from: new Address(
                config('mail.from.address', 'noreply@example.com'),
                config('mail.from.name', 'Envios Comerciales')
            ),
            replyTo: [
                new Address('envioscomercialestj@gmail.com', 'Envios Comerciales Support'),
            ],
            subject: $subject,
            tags: ['payment-received', 'order-paid'],
            metadata: [
                'order_id' => (string) $this->order->id,
                'order_number' => $this->order->order_number,
                'amount_paid' => (string) $this->order->amount_paid,
            ],
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
            view: 'emails.orders.payment-received',
            with: [
                'order' => $this->order,
                'user' => $this->order->user,
                'locale' => $this->order->user->preferred_language ?? 'es',
                'amountPaid' => $this->order->amount_paid,
                'currency' => strtoupper($this->order->currency ?? 'mxn'),
                'paidAt' => $this->order->paid_at ? Carbon::parse($this->order->paid_at) : now(),
                'stripeInvoiceId' => $this->order->stripe_invoice_id,
                'deliveryAddress' => $this->formatDeliveryAddress(),
                'itemCount' => $this->order->items()->count(),
                'totalWeight' => $this->order->actual_weight ?? $this->order->total_weight,
                'estimatedDelivery' => $this->getEstimatedDelivery(),
                'frontendUrl' => config('app.frontend_url'),
                'supportEmail' => 'envioscomercialestj@gmail.com',
                'supportPhone' => '+52 (664) 123-4567',
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
        // You could attach a payment receipt PDF here if needed
        return [];
    }

    /**
     * Get the tags that should be assigned to the message.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'payment-received',
            'order-paid',
            'order-' . $this->order->id,
            'user-' . $this->order->user_id,
            'amount-' . intval($this->order->amount_paid),
        ];
    }

    /**
     * Get the metadata that should be assigned to the message.
     *
     * @return array<string, string>
     */
    public function metadata(): array
    {
        return [
            'order_id' => (string) $this->order->id,
            'order_number' => $this->order->order_number,
            'user_id' => (string) $this->order->user_id,
            'user_email' => $this->order->user->email,
            'amount_paid' => (string) $this->order->amount_paid,
            'currency' => $this->order->currency ?? 'mxn',
            'stripe_invoice_id' => $this->order->stripe_invoice_id ?? '',
            'stripe_payment_intent_id' => $this->order->stripe_payment_intent_id ?? '',
            'paid_at' => $this->order->paid_at ? $this->order->paid_at->toIso8601String() : now()->toIso8601String(),
        ];
    }

    /**
     * Format the delivery address for display
     *
     * @return array
     */
    private function formatDeliveryAddress(): array
    {
        $address = $this->order->delivery_address ?? [];
        
        return [
            'street' => $address['street'] ?? '',
            'exterior_number' => $address['exterior_number'] ?? '',
            'interior_number' => $address['interior_number'] ?? null,
            'colonia' => $address['colonia'] ?? '',
            'municipio' => $address['municipio'] ?? '',
            'estado' => $address['estado'] ?? '',
            'postal_code' => $address['postal_code'] ?? '',
            'referencias' => $address['referencias'] ?? null,
            'full_address' => $this->buildFullAddress($address),
        ];
    }

    /**
     * Build full address string
     *
     * @param array $address
     * @return string
     */
    private function buildFullAddress(array $address): string
    {
        $parts = [];
        
        if (!empty($address['street']) && !empty($address['exterior_number'])) {
            $street = $address['street'] . ' ' . $address['exterior_number'];
            if (!empty($address['interior_number'])) {
                $street .= ' Int. ' . $address['interior_number'];
            }
            $parts[] = $street;
        }
        
        if (!empty($address['colonia'])) {
            $parts[] = $address['colonia'];
        }
        
        if (!empty($address['municipio']) && !empty($address['estado'])) {
            $parts[] = $address['municipio'] . ', ' . $address['estado'];
        }
        
        if (!empty($address['postal_code'])) {
            $parts[] = 'C.P. ' . $address['postal_code'];
        }
        
        return implode(', ', $parts);
    }

    /**
     * Get estimated delivery information
     *
     * @return array
     */
    private function getEstimatedDelivery(): array
    {
        $locale = $this->order->user->preferred_language ?? 'es';
        
        // Calculate estimated delivery (3-7 business days from now)
        $minDays = 3;
        $maxDays = 7;
        
        $minDate = now()->addWeekdays($minDays);
        $maxDate = now()->addWeekdays($maxDays);
        
        return [
            'min_date' => $minDate,
            'max_date' => $maxDate,
            'range_text' => $locale === 'es' 
                ? $minDays . '-' . $maxDays . ' días hábiles'
                : $minDays . '-' . $maxDays . ' business days',
            'formatted_range' => $locale === 'es'
                ? $minDate->format('d/m') . ' - ' . $maxDate->format('d/m/Y')
                : $minDate->format('m/d') . ' - ' . $maxDate->format('m/d/Y'),
        ];
    }

    /**
     * Determine if the message should be sent.
     *
     * @return bool
     */
    public function shouldSend(): bool
    {
        // Only send if order is actually paid
        return $this->order->isPaid() && 
               !empty($this->order->amount_paid) &&
               $this->order->amount_paid > 0;
    }
}