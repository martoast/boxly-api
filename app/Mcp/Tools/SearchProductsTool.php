<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\ProductExtractController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Universal product search across the entire US market (Google Shopping).
 * Public capability — available to both customer and admin connections.
 */
class SearchProductsTool extends BoxlyTool
{
    public function name(): string
    {
        return 'search_products';
    }

    public function description(): string
    {
        return 'Search real products across the ENTIRE US market (Google Shopping). Works for ANY store/brand on any platform (Shopify, headless, JS-rendered, even Cloudflare-blocked sites like Gymshark/Nike). Returns products with title, USD price, the store each comes from, and a link. Use to find anything the user wants Boxly to buy in the US and ship to Mexico.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('query')->description('What to find, e.g. "fleece joggers", "white sneakers under 150".')->required();
        $schema->string('store')->description('Optional brand/store to focus on, e.g. "Gymshark".');
        $schema->integer('limit')->description('Max results (default 12).');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guard(function () use ($arguments) {
            $this->mergeInput([
                'query' => $arguments['query'] ?? null,
                'store' => $arguments['store'] ?? null,
                'limit' => $arguments['limit'] ?? 12,
            ]);

            $data = app(ProductExtractController::class)->search(request())->getData(true);
            $data = $data['data'] ?? $data;

            // Strip base64 thumbnails — useless and enormous for an AI agent.
            if (! empty($data['products']) && is_array($data['products'])) {
                $data['products'] = array_map(function ($p) {
                    if (isset($p['image']) && is_string($p['image']) && str_starts_with($p['image'], 'data:')) {
                        $p['image'] = null;
                    }
                    return $p;
                }, $data['products']);
            }

            return ToolResult::json($data);
        });
    }
}
