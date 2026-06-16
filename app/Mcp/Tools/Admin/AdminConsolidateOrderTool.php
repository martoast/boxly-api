<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminOrderController;
use App\Models\Order;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminConsolidateOrderTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_consolidate_order';
    }

    public function description(): string
    {
        return 'Consolidate an order into a shipping box: creates the box, generates the Stripe invoice/payment link, schedules the ship date, and moves the order to awaiting_payment. The order must be in packages_complete. Use admin_list_boxes to get the stripe_price_id. Confirm with the user first.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('order_id')->description('The order id (must be packages_complete).')->required();
        $schema->string('stripe_price_id')->description('Box stripe price_id from admin_list_boxes.')->required();
        $schema->string('planned_ship_date')->description('Planned ship date YYYY-MM-DD.')->required();
        $schema->integer('quantity')->description('How many of this box (default 1).');
        $schema->number('length')->description('Box length cm (optional).');
        $schema->number('width')->description('Box width cm (optional).');
        $schema->number('height')->description('Box height cm (optional).');
        $schema->number('weight')->description('Box weight kg (optional).');
        $schema->number('price')->description('Optional MXN override of the box list price.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $order = Order::find($arguments['order_id'] ?? null);
            if (! $order) {
                return ToolResult::error('Order not found.');
            }

            $box = array_filter([
                'stripe_price_id' => $arguments['stripe_price_id'] ?? null,
                'quantity' => $arguments['quantity'] ?? 1,
                'length' => $arguments['length'] ?? null,
                'width' => $arguments['width'] ?? null,
                'height' => $arguments['height'] ?? null,
                'weight' => $arguments['weight'] ?? null,
                'price' => $arguments['price'] ?? null,
            ], fn ($v) => $v !== null);

            $this->mergeInput([
                'planned_ship_date' => $arguments['planned_ship_date'] ?? null,
                'payment_method' => 'stripe',
            ]);
            request()->merge(['boxes' => [$box]]);

            return $this->ok(app(AdminOrderController::class)->consolidateOrder(request(), $order));
        });
    }
}
