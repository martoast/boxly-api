<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\PurchaseRequestController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class CreatePurchaseRequestTool extends BoxlyTool
{
    public function name(): string
    {
        return 'create_purchase_request';
    }

    public function description(): string
    {
        return 'Ask Boxly to BUY items in the US on the customer\'s behalf (the concierge "paste a link" flow). Boxly reviews, quotes, buys, and ships to Mexico. Confirm with the user before creating. Provide one or more items; price is the listed USD price (use 0 if unknown — Boxly will quote).';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->raw('items', [
            'type' => 'array',
            'description' => 'Items to request.',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'product_name' => ['type' => 'string', 'description' => 'Product name.'],
                    'product_url' => ['type' => 'string', 'description' => 'Product URL (the link to buy).'],
                    'price' => ['type' => 'number', 'description' => 'Listed price in USD (0 if unknown).'],
                    'quantity' => ['type' => 'integer', 'description' => 'Quantity (default 1).'],
                    'notes' => ['type' => 'string', 'description' => 'Optional notes (size, color, variant).'],
                ],
                'required' => ['product_name', 'product_url'],
            ],
        ])->required();

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guard(function () use ($arguments) {
            $items = array_map(fn ($it) => [
                'product_name' => $it['product_name'] ?? null,
                'product_url' => $it['product_url'] ?? null,
                'price' => $it['price'] ?? 0,
                'quantity' => $it['quantity'] ?? 1,
                'notes' => $it['notes'] ?? null,
            ], $arguments['items'] ?? []);

            request()->merge(['currency' => 'usd', 'items' => $items]);
            return $this->ok(app(PurchaseRequestController::class)->store(request()));
        });
    }
}
