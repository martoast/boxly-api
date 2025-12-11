<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class OrderShipped extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {}

    public function envelope(): Envelope
    {
        $locale = $this->order->user->preferred_language ?? 'es';

        $subject = $locale === 'es'
            ? '📦 Tu Orden Ha Sido Enviada - ' . $this->order->order_number
            : '📦 Your Order Has Been Shipped - ' . $this->order->order_number;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $locale = $this->order->user->preferred_language ?? 'es';
        $boxes = $this->order->boxes;

        // Estafeta tracking URL
        $trackingBaseUrl = 'https://www.estafeta.com/rastrear-envio';

        // Format estimated delivery
        $estimatedDelivery = null;
        if ($this->order->estimated_delivery_date) {
            $date = $this->order->estimated_delivery_date;
            $estimatedDelivery = $locale === 'es'
                ? $date->format('d/m/Y')
                : $date->format('m/d/Y');
        }

        return new Content(
            view: 'emails.orders.shipped',
            with: [
                'order' => $this->order,
                'user' => $this->order->user,
                'locale' => $locale,
                'boxes' => $boxes,
                'trackingUrl' => $trackingBaseUrl,
                'estimatedDelivery' => $estimatedDelivery,
                'giaUrl' => $this->order->gia_full_url,
            ]
        );
    }

    /**
     * Attach GIA files for each box.
     */
    public function attachments(): array
    {
        $attachments = [];
        $boxes = $this->order->boxes;

        foreach ($boxes as $index => $box) {
            if ($box->gia_path) {
                $boxNumber = $index + 1;
                $filename = $box->gia_filename ?? "gia-box-{$boxNumber}.pdf";

                if ($boxes->count() > 1) {
                    $filename = "Box{$boxNumber}-{$filename}";
                }

                $attachments[] = Attachment::fromStorageDisk('spaces', $box->gia_path)
                    ->as($filename)
                    ->withMime($box->gia_mime_type ?? 'application/pdf');
            }
        }

        // Fallback to order-level GIA for legacy
        if (empty($attachments) && $this->order->gia_path) {
            $attachments[] = Attachment::fromStorageDisk('spaces', $this->order->gia_path)
                ->as($this->order->gia_filename ?? 'gia.pdf')
                ->withMime($this->order->gia_mime_type ?? 'application/pdf');
        }

        return $attachments;
    }
}
