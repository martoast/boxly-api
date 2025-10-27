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
        $this->order = $order->load('user', 'items');
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

            // Format dates for GoHighLevel (MM-DD-YYYY)
            $orderCreatedAt = $this->order->created_at ? $this->order->created_at->format('m-d-Y') : null;
            $completedAt = $this->order->completed_at ? $this->order->completed_at->format('m-d-Y') : null;

            $payload = [
                'event' => 'order_placed',
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'order_number' => $this->order->order_number,
                'tracking_number' => $this->order->tracking_number,
                'order_id' => $this->order->id,
                'order_created_at' => $orderCreatedAt,
                'order_completed_at' => $completedAt,
                'source' => 'boxly_api',
                'opportunity_title' => 'Boxly Order - ' . $this->order->order_number,
                'opportunity_status' => 'open',
                'pipeline_stage' => 'Order Completed - Awaiting Packages',
                'monetary_value' => $this->order->quoted_amount ?? 0,
                'order' => $this->formatOrderForWebhook($this->order),
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

    /**
     * Format order object with GoHighLevel-friendly date formats
     */
    private function formatOrderForWebhook(Order $order): array
    {
        $orderArray = $order->toArray();

        // Format all date fields to MM-DD-YYYY
        $dateFields = [
            'created_at',
            'updated_at',
            'completed_at',
            'processing_started_at',
            'quote_sent_at',
            'quote_expires_at',
            'shipped_at',
            'delivered_at',
            'paid_at',
            'estimated_delivery_date',
            'actual_delivery_date',
        ];

        foreach ($dateFields as $field) {
            if (isset($orderArray[$field]) && $orderArray[$field]) {
                $date = is_string($orderArray[$field]) 
                    ? \Carbon\Carbon::parse($orderArray[$field]) 
                    : $orderArray[$field];
                $orderArray[$field] = $date->format('m-d-Y');
            }
        }

        // Format dates in items
        if (isset($orderArray['items']) && is_array($orderArray['items'])) {
            foreach ($orderArray['items'] as &$item) {
                if (isset($item['estimated_delivery_date']) && $item['estimated_delivery_date']) {
                    $date = is_string($item['estimated_delivery_date']) 
                        ? \Carbon\Carbon::parse($item['estimated_delivery_date']) 
                        : $item['estimated_delivery_date'];
                    $item['estimated_delivery_date'] = $date->format('m-d-Y');
                }
                if (isset($item['arrived_at']) && $item['arrived_at']) {
                    $date = is_string($item['arrived_at']) 
                        ? \Carbon\Carbon::parse($item['arrived_at']) 
                        : $item['arrived_at'];
                    $item['arrived_at'] = $date->format('m-d-Y');
                }
                if (isset($item['created_at']) && $item['created_at']) {
                    $date = is_string($item['created_at']) 
                        ? \Carbon\Carbon::parse($item['created_at']) 
                        : $item['created_at'];
                    $item['created_at'] = $date->format('m-d-Y');
                }
                if (isset($item['updated_at']) && $item['updated_at']) {
                    $date = is_string($item['updated_at']) 
                        ? \Carbon\Carbon::parse($item['updated_at']) 
                        : $item['updated_at'];
                    $item['updated_at'] = $date->format('m-d-Y');
                }
            }
        }

        // Format dates in user
        if (isset($orderArray['user'])) {
            if (isset($orderArray['user']['created_at']) && $orderArray['user']['created_at']) {
                $date = is_string($orderArray['user']['created_at']) 
                    ? \Carbon\Carbon::parse($orderArray['user']['created_at']) 
                    : $orderArray['user']['created_at'];
                $orderArray['user']['created_at'] = $date->format('m-d-Y');
            }
            if (isset($orderArray['user']['updated_at']) && $orderArray['user']['updated_at']) {
                $date = is_string($orderArray['user']['updated_at']) 
                    ? \Carbon\Carbon::parse($orderArray['user']['updated_at']) 
                    : $orderArray['user']['updated_at'];
                $orderArray['user']['updated_at'] = $date->format('m-d-Y');
            }
            if (isset($orderArray['user']['email_verified_at']) && $orderArray['user']['email_verified_at']) {
                $date = is_string($orderArray['user']['email_verified_at']) 
                    ? \Carbon\Carbon::parse($orderArray['user']['email_verified_at']) 
                    : $orderArray['user']['email_verified_at'];
                $orderArray['user']['email_verified_at'] = $date->format('m-d-Y');
            }
        }

        return $orderArray;
    }
}