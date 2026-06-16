<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\OrderController;
use App\Models\Order;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class GetOrderTool extends BoxlyTool
{
    public function name(): string
    {
        return 'get_order';
    }

    public function description(): string
    {
        return 'Get full detail of one of the customer\'s orders by id, including its items/packages and status.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('order_id')->description('The order id (from list_orders).')->required();

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guard(function () use ($arguments) {
            $order = Order::where('user_id', $this->user()->id)->find($arguments['order_id'] ?? null);
            if (! $order) {
                return ToolResult::error('Order not found.');
            }
            return $this->ok(app(OrderController::class)->show(request(), $order));
        });
    }
}
