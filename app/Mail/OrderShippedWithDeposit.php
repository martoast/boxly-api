<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment; // Import Attachment
use Illuminate\Queue\SerializesModels;

class OrderShippedWithDeposit extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {}

    public function envelope(): Envelope
    {
        $locale = $this->order->user->preferred_language ?? 'es';
        $isCrossing = $this->order->isCrossingOnly();

        if ($isCrossing) {
            $subject = $locale === 'es'
                ? '📦 Paquetes Recibidos - Pago para Iniciar Cruce - ' . $this->order->tracking_number
                : '📦 Packages Received - Payment to Begin Crossing - ' . $this->order->tracking_number;
        } else {
            $subject = $locale === 'es'
                ? '📦 Orden Enviada - Guía y Depósito - ' . $this->order->tracking_number
                : '📦 Order Shipped - Tracking and Deposit - ' . $this->order->tracking_number;
        }

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $cleanGuia = str_replace(' ', '', $this->order->guia_number ?? '');
        $trackingLink = "https://contactaftershipmh6u.aftership.com/";

        // Get box information for the email
        $boxes = $this->order->boxes;
        $hasMultipleBoxes = $this->order->hasMultipleBoxes();
        $boxSummary = $this->order->box_summary;
        $totalBoxPrice = $this->order->calculateTotalBoxPrice();

        // Crossing order pickup location
        $isCrossing = $this->order->isCrossingOnly();
        $pickupLocation = [
            'name' => 'Colectivo Las Ferias La Cacho',
            'mapsLink' => 'https://maps.app.goo.gl/4SsEVjy2D4noFM9n8',
            'phone' => '+1 (619) 559-1920',
            'hours' => 'Lunes - Viernes: 9:00 AM - 5:00 PM',
            'instructions' => 'Por favor llama antes para coordinar tu recogida',
        ];

        return new Content(
            view: 'emails.orders.shipped-with-deposit',
            with: [
                'order' => $this->order,
                'user' => $this->order->user,
                'locale' => $this->order->user->preferred_language ?? 'es',
                'trackingLink' => $trackingLink,
                'depositLink' => $this->order->deposit_payment_link,
                // We still pass the URL as a backup in case they can't open the attachment
                'giaUrl' => $this->order->gia_full_url,
                // Multi-box support
                'boxes' => $boxes,
                'hasMultipleBoxes' => $hasMultipleBoxes,
                'boxSummary' => $boxSummary,
                'totalBoxPrice' => $totalBoxPrice,
                // Crossing order support
                'isCrossingOnly' => $isCrossing,
                'pickupLocation' => $pickupLocation,
            ]
        );
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        // Crossing orders don't have GIA files
        if ($this->order->isCrossingOnly()) {
            return [];
        }

        // If for some reason the path is missing, return empty array
        if (! $this->order->gia_path) {
            return [];
        }

        // This pulls the file directly from your 'spaces' disk
        return [
            Attachment::fromStorageDisk('spaces', $this->order->gia_path)
                ->as($this->order->gia_filename ?? 'guia.pdf')
                ->withMime($this->order->gia_mime_type ?? 'application/pdf'),
        ];
    }
}