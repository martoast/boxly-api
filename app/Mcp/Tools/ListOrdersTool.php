<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\OrderController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class ListOrdersTool extends BoxlyTool
{
    public function name(): string
    {
        return 'list_orders';
    }

    public function description(): string
    {
        return "List the customer's forwarding/consolidation orders, most recent first. Optionally filter by status or search by order/tracking number.";
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('status')->description('Optional status filter, e.g. collecting, awaiting_packages, paid, shipped, delivered.');
        $schema->string('search')->description('Optional search by order number or tracking number.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guard(function () use ($arguments) {
            request()->merge(['status' => $arguments['status'] ?? null, 'search' => $arguments['search'] ?? null]);
            return $this->ok(app(OrderController::class)->index(request()));
        });
    }
}
