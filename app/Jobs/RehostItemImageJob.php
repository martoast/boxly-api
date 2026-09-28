<?php

namespace App\Jobs;

use App\Models\PurchaseRequestItem;
use App\Services\PurchaseRequestIntake;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Copy an order item's product image into our Spaces bucket — in the background. Creating an order used to do it
 * inline, one download at a time (up to 20 s each): a two-store Lab Finalizar took so long that the chat's own
 * request was cut off and the shopper saw "Algo salió mal" while the order went through (live 2026-09-28). Until
 * the copy exists the item shows its original image URL (image_full_url falls back to it).
 */
class RehostItemImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public int $itemId)
    {
    }

    public function handle(PurchaseRequestIntake $intake): void
    {
        $item = PurchaseRequestItem::with('purchaseRequest.user')->find($this->itemId);
        $pr = $item?->purchaseRequest;
        if (! $item || ! $pr || ! $pr->user || blank($item->product_image_url) || filled($item->image_path)) {
            return;
        }
        $intake->rehostItemImage($item, $item->product_image_url, $pr->user, $pr);
    }
}
