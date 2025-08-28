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
            // Build webhook payload - KEEP IT SIMPLE like the test that worked
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
                $payload['customer_segment'] = $this->userType;
            }
            
            // Handle registration source - FIXED to not send objects
            if ($this->registrationSource) {
                // Try to decode if it's JSON string
                $sourceData = is_string($this->registrationSource) 
                    ? json_decode($this->registrationSource, true) 
                    : $this->registrationSource;
                
                if (is_array($sourceData)) {
                    // Add individual UTM parameters as simple string fields
                    if (!empty($sourceData['utm_source'])) {
                        $payload['utm_source'] = (string)$sourceData['utm_source'];
                    }
                    if (!empty($sourceData['utm_medium'])) {
                        $payload['utm_medium'] = (string)$sourceData['utm_medium'];
                    }
                    if (!empty($sourceData['utm_campaign'])) {
                        $payload['utm_campaign'] = (string)$sourceData['utm_campaign'];
                    }
                    if (!empty($sourceData['utm_content'])) {
                        $payload['utm_content'] = (string)$sourceData['utm_content'];
                    }
                    if (!empty($sourceData['utm_term'])) {
                        $payload['utm_term'] = (string)$sourceData['utm_term'];
                    }
                    if (!empty($sourceData['fbclid'])) {
                        $payload['fbclid'] = (string)$sourceData['fbclid'];
                    }
                    if (!empty($sourceData['landing_page'])) {
                        $payload['landing_page'] = (string)$sourceData['landing_page'];
                    }
                    
                    // Set lead_source as a SIMPLE STRING, not an object
                    // Priority: utm_source > landing_page > 'direct'
                    $leadSource = 'direct';
                    if (!empty($sourceData['utm_source'])) {
                        $leadSource = (string)$sourceData['utm_source'];
                    } elseif (!empty($sourceData['landing_page'])) {
                        $leadSource = (string)$sourceData['landing_page'];
                    }
                    $payload['lead_source'] = $leadSource;
                    
                    // Store full tracking data as JSON STRING (not object)
                    $payload['tracking_data'] = json_encode($sourceData);
                } else {
                    // If it's already a string, use it directly
                    $payload['lead_source'] = (string)$this->registrationSource;
                }
            } else {
                // No registration source provided
                $payload['lead_source'] = 'direct';
            }

            // Log what we're sending
            Log::info('Sending to GoHighLevel', [
                'url' => $webhookUrl,
                'name' => $payload['name'],
                'email' => $payload['email'],
                'phone' => $payload['phone'],
                'lead_source_type' => gettype($payload['lead_source'] ?? null),
                'lead_source_value' => $payload['lead_source'] ?? null,
            ]);

            $response = Http::timeout(30)->post($webhookUrl, $payload);

            if ($response->successful()) {
                Log::info('Successfully sent funnel capture to GoHighLevel', [
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'user_type' => $this->userType,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
            } else {
                Log::warning('GoHighLevel webhook returned non-success status', [
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send funnel capture to GoHighLevel', [
                'email' => $this->email,
                'phone' => $this->phone,
                'error' => $e->getMessage(),
            ]);
            
            // Re-throw to trigger retry
            throw $e;
        }
    }
}