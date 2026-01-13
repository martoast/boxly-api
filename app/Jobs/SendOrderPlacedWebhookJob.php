<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendOrderPlacedWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function handle(): void
    {
        $webhookUrl = config('services.gohighlevel.order_placed_webhook_url');

        if (!$webhookUrl) {
            Log::error('GoHighLevel "Order Placed" webhook URL is not configured.');
            return;
        }

        try {
            $payload = [
                'event' => 'user_converted',
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
                'converted' => true,
                'source' => 'boxly_api',
            ];

            Log::info('Sending "User Converted" webhook to GoHighLevel', [
                'url' => $webhookUrl,
                'payload' => $payload,
            ]);

            $response = Http::timeout(30)->post($webhookUrl, $payload);

            if ($response->successful()) {
                Log::info('Successfully sent "User Converted" webhook to GoHighLevel', [
                    'user_id' => $this->user->id,
                    'status' => $response->status(),
                ]);
            } else {
                Log::warning('GoHighLevel "User Converted" webhook returned non-success status', [
                    'user_id' => $this->user->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send "User Converted" webhook to GoHighLevel', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
