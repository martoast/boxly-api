<?php

namespace App\Jobs;

use App\Models\Order;
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

    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order->load('user');
    }

    public function handle(): void
    {
        $webhookUrl = config('services.gohighlevel.order_placed_webhook_url');

        if (!$webhookUrl) {
            Log::error('GoHighLevel "Order Placed" webhook URL is not configured.');
            return;
        }

        try {
            $user = $this->order->user;

            $payload = [
                'event' => 'order_placed',
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'order_number' => $this->order->order_number,
                'tracking_number' => $this->order->tracking_number,
                'order_id' => $this->order->id,
                'order_created_at' => $this->order->created_at->toIso8601String(),
                'source' => 'boxly_api',
                'opportunity_title' => 'Boxly Order - ' . $this->order->order_number,
                'opportunity_status' => 'open',
                'pipeline_stage' => 'New Order Created',
                'monetary_value' => 0,
            ];

            Log::info('Sending "Order Placed" webhook to GoHighLevel', [
                'url' => $webhookUrl,
                'payload' => $payload,
            ]);

            $response = Http::timeout(30)->post($webhookUrl, $payload);

            if ($response->successful()) {
                Log::info('Successfully sent "Order Placed" webhook to GoHighLevel', [
                    'order_id' => $this->order->id,
                    'status' => $response->status(),
                ]);
            } else {
                Log::warning('GoHighLevel "Order Placed" webhook returned non-success status', [
                    'order_id' => $this->order->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send "Order Placed" webhook to GoHighLevel', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}