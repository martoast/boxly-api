<?php

namespace App\Mail;

use App\Models\EmailCampaign;
use App\Models\CampaignRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

class CampaignEmail extends Mailable
{
    use Queueable, SerializesModels;

    public EmailCampaign $campaign;
    public CampaignRecipient $recipient;

    public function __construct(EmailCampaign $campaign, CampaignRecipient $recipient)
    {
        $this->campaign = $campaign;
        $this->recipient = $recipient;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('contact@boxly.mx', 'Boxly'),
            replyTo: [
                new Address('contact@boxly.mx', 'Boxly Support'),
            ],
            subject: $this->campaign->subject,
            metadata: [
                'campaign_id' => (string) $this->campaign->id,
                'recipient_id' => (string) $this->recipient->id,
            ],
            tags: ['campaign', 'campaign-' . $this->campaign->id],
        );
    }

    public function content(): Content
    {
        $appUrl = config('app.url');

        return new Content(
            view: 'emails.campaign',
            with: [
                'body' => $this->campaign->body,
                'linkUrl' => $this->campaign->link_url
                    ? "{$appUrl}/api/campaign/click/{$this->recipient->tracking_token}"
                    : null,
                'linkText' => $this->campaign->link_text ?? 'Learn More',
                'pixelUrl' => "{$appUrl}/api/campaign/pixel/{$this->recipient->tracking_token}",
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
