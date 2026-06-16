<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\OrderController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class CreateOrderTool extends BoxlyTool
{
    public function name(): string
    {
        return 'create_order';
    }

    public function description(): string
    {
        return 'Create a new order to collect/consolidate packages. Use type "shipping" to deliver to a Mexican address (delivery_address required) or "crossing" for warehouse pickup (no address). Confirm with the user before creating. Returns the new order with its number.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('order_type')->description('shipping = deliver to Mexico; crossing = pickup at the San Ysidro warehouse. Default shipping.');
        $schema->string('delivery_address')->description('Full delivery address as one line (street, number, colonia, municipio, estado, 5-digit CP). Required for shipping orders.');
        $schema->string('notes')->description('Optional notes for the order.');
        $schema->number('declared_value')->description('Optional declared value (MXN).');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guard(function () use ($arguments) {
            $type = $arguments['order_type'] ?? 'shipping';
            $merge = [
                'order_type' => $type,
                'notes' => $arguments['notes'] ?? null,
                'declared_value' => $arguments['declared_value'] ?? null,
            ];
            if ($type !== 'crossing' && ! empty($arguments['delivery_address'])) {
                $merge['delivery_address'] = ['full_address' => $arguments['delivery_address']];
            }
            $this->mergeInput($merge);
            return $this->ok(app(OrderController::class)->create(request()));
        });
    }
}
