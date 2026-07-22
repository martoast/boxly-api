<?php

namespace App\Jobs;

use App\Models\OrderStatusEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Append one row to the order_status_events outbox. Fired from the Order
 * model's status watcher for EVERY transition (and creation, from=null).
 * Scalars only — serializing the Order would re-read state that may have
 * moved on by the time the queue worker runs.
 */
class RecordOrderStatusEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $orderId,
        public int $userId,
        public ?string $fromStatus,
        public string $toStatus,
    ) {
    }

    public function handle(): void
    {
        try {
            OrderStatusEvent::create([
                'order_id' => $this->orderId,
                'user_id' => $this->userId,
                'from_status' => $this->fromStatus,
                'to_status' => $this->toStatus,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to record order status event', [
                'order_id' => $this->orderId,
                'to_status' => $this->toStatus,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
