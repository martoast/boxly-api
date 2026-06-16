<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\OrderController;
use App\Models\Order;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class GetOrderPaymentLinkTool extends BoxlyTool
{
    public function name(): string
    {
        return 'get_order_payment_link';
    }

    public function description(): string
    {
        return 'Get a Stripe checkout LINK to pay an order\'s shipping quote. This NEVER charges the customer — it returns a URL the customer opens themselves to pay. Use only when the customer explicitly asks to pay.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('order_id')->description('The order id with a ready quote.')->required();

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guard(function () use ($arguments) {
            $order = Order::where('user_id', $this->user()->id)->find($arguments['order_id'] ?? null);
            if (! $order) {
                return ToolResult::error('Order not found.');
            }
            $resp = app(OrderController::class)->payQuote(request(), $order);
            $data = $resp->getData(true);
            $url = $data['url'] ?? $data['checkout_url'] ?? ($data['data']['url'] ?? null);
            return ToolResult::json([
                'payment_link' => $url,
                'note' => 'Open this link to pay. Your AI does not charge you automatically.',
            ]);
        });
    }
}
