<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\ProductExtractController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Pull a real product feed straight from a (Shopify) US store's own catalog —
 * latest drop, in-store keyword search, or current deals. Public capability;
 * available to both customer and admin connections.
 */
class BrowseStoreTool extends BoxlyTool
{
    public function name(): string
    {
        return 'browse_store';
    }

    public function description(): string
    {
        return "Pull real products from a specific Shopify US store's own catalog (e.g. youngla.com, gymshark fails — use search_products for those). Provide the store URL; optional keyword to search within it, or sale=true for items currently on sale. Returns products with title, USD price, image URL and product URL. For stores this can't reach (returns empty), use search_products instead.";
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('url')->description('Store homepage or any URL on it, e.g. https://www.youngla.com')->required();
        $schema->string('query')->description('Optional keyword to search within the store, e.g. "joggers"; omit for the latest drop.');
        $schema->boolean('sale')->description('If true, return only items currently on sale (deals).');
        $schema->integer('limit')->description('Max results (default 12).');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guard(function () use ($arguments) {
            $this->mergeInput([
                'url'   => $arguments['url'] ?? null,
                'query' => $arguments['query'] ?? null,
                'sale'  => $arguments['sale'] ?? null,
                'limit' => $arguments['limit'] ?? 12,
            ]);

            return $this->ok(app(ProductExtractController::class)->storeFeed(request()));
        });
    }
}
