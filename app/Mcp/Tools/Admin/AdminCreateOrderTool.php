<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminOrderManagementController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminCreateOrderTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_create_order';
    }

    public function description(): string
    {
        return 'Create an order on behalf of a customer (admin). Provide their user id. For shipping orders give a delivery_address. Confirm with the user first.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('user_id')->description('The customer (user) id the order is for.')->required();
        $schema->string('order_type')->description('shipping (default) or crossing.');
        $schema->string('delivery_address')->description('Full delivery address as one line (required for shipping).');
        $schema->string('status')->description('Optional initial status (default collecting). e.g. packages_complete to make it ready to consolidate.');
        $schema->string('notes')->description('Optional notes.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $type = $arguments['order_type'] ?? 'shipping';
            $this->mergeInput([
                'user_id' => $arguments['user_id'] ?? null,
                'order_type' => $type,
                'status' => $arguments['status'] ?? null,
                'notes' => $arguments['notes'] ?? null,
            ]);
            if ($type !== 'crossing' && ! empty($arguments['delivery_address'])) {
                request()->merge(['delivery_address' => ['full_address' => $arguments['delivery_address']]]);
            }
            return $this->ok(app(AdminOrderManagementController::class)->createOrder(request()));
        });
    }
}
