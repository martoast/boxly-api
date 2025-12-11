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

class OrderConsolidatedInvoice extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
        public string $paymentMethod = 'stripe'
    ) {}

    public function envelope(): Envelope
    {
        $locale = $this->order->user->preferred_language ?? 'es';

        $subject = $locale === 'es'
            ? '🎉 ¡Todos tus productos han llegado! - Factura Lista - ' . $this->order->tracking_number
            : '🎉 All Your Items Have Arrived! - Invoice Ready - ' . $this->order->tracking_number;

        return new Envelope(
            from: new Address(config('mail.from.address'), config('mail.from.name')),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $boxes = $this->order->boxes;
        $boxSummary = $this->order->box_summary;
        $totalBoxPrice = $this->order->calculateTotalBoxPrice();
        $isCrossing = $this->order->isCrossingOnly();

        // Bank details for manual transfer
        $bankDetails = [
            'beneficiary_name' => config('services.nu_bank.beneficiary_name'),
            'bank_name' => config('services.nu_bank.bank_name'),
            'account_number' => config('services.nu_bank.account_number'),
        ];

        return new Content(
            view: 'emails.orders.consolidated-invoice',
            with: [
                'order' => $this->order,
                'user' => $this->order->user,
                'items' => $this->order->items,
                'locale' => $this->order->user->preferred_language ?? 'es',
                'paymentLink' => $this->order->payment_link,
                'boxes' => $boxes,
                'boxSummary' => $boxSummary,
                'totalBoxPrice' => $totalBoxPrice,
                'isCrossingOnly' => $isCrossing,
                'paymentMethod' => $this->paymentMethod,
                'bankDetails' => $bankDetails,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
