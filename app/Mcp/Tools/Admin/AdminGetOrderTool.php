<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminOrderController;
use App\Models\Order;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminGetOrderTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_get_order';
    }

    public function description(): string
    {
        return 'Get full detail of any order by id, including customer, items/packages and boxes.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('order_id')->description('The order id.')->required();

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $order = Order::find($arguments['order_id'] ?? null);
            if (! $order) {
                return ToolResult::error('Order not found.');
            }
            return $this->ok(app(AdminOrderController::class)->show($order));
        });
    }
}
