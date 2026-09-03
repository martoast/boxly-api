<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\ProductExtractController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Read clean product details (title, USD price, image, store) from a specific
 * US product page URL. Public capability; both customer and admin.
 */
class ExtractProductTool extends BoxlyTool
{
    public function name(): string
    {
        return 'extract_product';
    }

    public function description(): string
    {
        return 'Get clean details (title, USD price, image, store) from a specific US product page URL — use to confirm a product before creating a purchase request.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('url')->description('The product page URL.')->required();

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guard(function () use ($arguments) {
            $this->mergeInput(['url' => $arguments['url'] ?? null]);

            return $this->ok(app(ProductExtractController::class)->extract(request()));
        });
    }
}
