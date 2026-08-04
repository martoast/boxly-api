<?php

namespace App\Mail;

use App\Models\DropOffReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DropOffReceiptCreated extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public DropOffReceipt $receipt
    ) {}

    public function envelope(): Envelope
    {
        $locale = $this->receipt->user->preferred_language ?? 'es';

        return new Envelope(
            subject: __('emails.drop_off.receipt.subject', [
                'receipt_number' => $this->receipt->receipt_number,
            ], $locale),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.drop-off-receipt',
            with: [
                'user'   => $this->receipt->user,
                'images' => $this->receipt->images ?? [],
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
