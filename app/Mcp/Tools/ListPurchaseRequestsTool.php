<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\PurchaseRequestController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class ListPurchaseRequestsTool extends BoxlyTool
{
    public function name(): string
    {
        return 'list_purchase_requests';
    }

    public function description(): string
    {
        return "List the customer's purchase requests (items they asked Boxly to buy for them in the US), most recent first.";
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guard(fn () => $this->ok(app(PurchaseRequestController::class)->index(request())));
    }
}
