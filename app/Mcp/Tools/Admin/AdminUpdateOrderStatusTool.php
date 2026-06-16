<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminOrderController;
use App\Http\Requests\AdminUpdateOrderStatusRequest;
use App\Models\Order;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminUpdateOrderStatusTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_update_order_status';
    }

    public function description(): string
    {
        return 'Change an order\'s status (e.g. processing, shipped, delivered, cancelled). Confirm with the user first. When setting shipped, provide estimated_delivery_date.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('order_id')->description('The order id.')->required();
        $schema->string('status')->description('New status: collecting, awaiting_packages, packages_complete, awaiting_payment, paid, processing, shipped, delivered, cancelled.')->required();
        $schema->string('estimated_delivery_date')->description('YYYY-MM-DD, required when status is shipped.');
        $schema->string('notes')->description('Optional note.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $order = Order::find($arguments['order_id'] ?? null);
            if (! $order) {
                return ToolResult::error('Order not found.');
            }
            $form = $this->formRequest(AdminUpdateOrderStatusRequest::class, [
                'status' => $arguments['status'] ?? null,
                'estimated_delivery_date' => $arguments['estimated_delivery_date'] ?? null,
                'notes' => $arguments['notes'] ?? null,
            ], ['order' => $order]);
            return $this->ok(app(AdminOrderController::class)->updateStatus($form, $order));
        });
    }
}
