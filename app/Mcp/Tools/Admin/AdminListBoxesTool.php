<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\ProductController;
use Laravel\Mcp\Server\Tools\ToolResult;
use Laravel\Mcp\Server\Tools\ToolInputSchema;

class AdminListBoxesTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_list_boxes';
    }

    public function description(): string
    {
        return 'List the Stripe shipping-box catalog (sizes, prices, and the stripe price_id needed to consolidate an order).';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(fn () => $this->ok(app(ProductController::class)->index(request())));
    }
}
