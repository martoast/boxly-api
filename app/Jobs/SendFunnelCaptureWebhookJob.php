<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendFunnelCaptureWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $name,
        protected string $email,
        protected string $phone
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $webhookUrl = config('services.gohighlevel.webhook_url');

        if (!$webhookUrl) {
            Log::error('GoHighLevel webhook URL is not configured');
            return;
        }

        try {
            $response = Http::timeout(30)
                ->post($webhookUrl, [
                    'name' => $this->name,
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'source' => 'funnel_capture',
                    'captured_at' => now()->toIso8601String(),
                ]);

            if ($response->successful()) {
                Log::info('Successfully sent funnel capture to GoHighLevel', [
                    'email' => $this->email,
                    'status' => $response->status(),
                ]);
            } else {
                Log::warning('GoHighLevel webhook returned non-success status', [
                    'email' => $this->email,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send funnel capture to GoHighLevel', [
                'email' => $this->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}