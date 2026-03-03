<?php

namespace App\Jobs;

use App\Models\EmailCampaign;
use App\Models\CampaignRecipient;
use App\Mail\CampaignEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class ProcessCampaignBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $campaignId;

    public function __construct(int $campaignId)
    {
        $this->campaignId = $campaignId;
    }

    public function handle(): void
    {
        $campaign = EmailCampaign::find($this->campaignId);

        if (!$campaign || $campaign->status !== EmailCampaign::STATUS_SENDING) {
            Log::info('Campaign batch skipped — not in sending status', ['campaign_id' => $this->campaignId]);
            return;
        }

        $recipients = $campaign->recipients()
            ->where('status', CampaignRecipient::STATUS_PENDING)
            ->limit($campaign->batch_size)
            ->get();

        if ($recipients->isEmpty()) {
            $campaign->update([
                'status' => EmailCampaign::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
            Log::info('Campaign completed', ['campaign_id' => $campaign->id]);
            return;
        }

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient->email)->send(new CampaignEmail($campaign, $recipient));

                $recipient->update([
                    'status' => CampaignRecipient::STATUS_SENT,
                    'sent_at' => now(),
                ]);
                $campaign->increment('sent_count');
            } catch (\Exception $e) {
                Log::error('Campaign email failed', [
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);

                $recipient->update([
                    'status' => CampaignRecipient::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        // Self-dispatch for next batch
        self::dispatch($this->campaignId)
            ->delay(now()->addSeconds($campaign->batch_delay_seconds));

        Log::info('Campaign batch processed', [
            'campaign_id' => $campaign->id,
            'batch_sent' => $recipients->count(),
        ]);
    }
}
