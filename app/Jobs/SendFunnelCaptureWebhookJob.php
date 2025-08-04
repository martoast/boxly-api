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
        protected string $phone,
        protected ?string $userType = null,
        protected mixed $registrationSource = null // Can be array or string
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
            // Build webhook payload
            $payload = [
                'name' => $this->name,
                'email' => $this->email,
                'phone' => $this->phone,
                'source' => 'funnel_capture',
                'captured_at' => now()->toIso8601String(),
            ];
            
            // Add user type if provided
            if ($this->userType) {
                $payload['user_type'] = $this->userType;
                $payload['customer_segment'] = $this->userType; // Alternative field name for CRM
            }
            
            // Handle registration source - can be array or string
            if ($this->registrationSource) {
                if (is_array($this->registrationSource)) {
                    // Add individual UTM parameters for CRM
                    if (isset($this->registrationSource['utm_source'])) {
                        $payload['utm_source'] = $this->registrationSource['utm_source'];
                    }
                    if (isset($this->registrationSource['utm_medium'])) {
                        $payload['utm_medium'] = $this->registrationSource['utm_medium'];
                    }
                    if (isset($this->registrationSource['utm_campaign'])) {
                        $payload['utm_campaign'] = $this->registrationSource['utm_campaign'];
                    }
                    if (isset($this->registrationSource['utm_content'])) {
                        $payload['utm_content'] = $this->registrationSource['utm_content'];
                    }
                    if (isset($this->registrationSource['utm_term'])) {
                        $payload['utm_term'] = $this->registrationSource['utm_term'];
                    }
                    if (isset($this->registrationSource['fbclid'])) {
                        $payload['fbclid'] = $this->registrationSource['fbclid'];
                    }
                    if (isset($this->registrationSource['landing_page'])) {
                        $payload['landing_page'] = $this->registrationSource['landing_page'];
                    }
                    
                    // Also send the full tracking data as JSON string
                    $payload['tracking_data'] = json_encode($this->registrationSource);
                } else {
                    // If it's a string, treat it as a simple source
                    $payload['lead_source'] = $this->registrationSource;
                }
            }

            $response = Http::timeout(30)->post($webhookUrl, $payload);

            if ($response->successful()) {
                Log::info('Successfully sent funnel capture to GoHighLevel', [
                    'email' => $this->email,
                    'user_type' => $this->userType,
                    'registration_source' => $this->registrationSource,
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