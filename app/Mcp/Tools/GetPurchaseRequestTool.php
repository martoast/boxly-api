<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\PurchaseRequestController;
use App\Models\PurchaseRequest;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class GetPurchaseRequestTool extends BoxlyTool
{
    public function name(): string
    {
        return 'get_purchase_request';
    }

    public function description(): string
    {
        return 'Get full detail of one of the customer\'s purchase requests by id, including items, status, and any quote.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('purchase_request_id')->description('The purchase request id.')->required();

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guard(function () use ($arguments) {
            $pr = PurchaseRequest::where('user_id', $this->user()->id)->find($arguments['purchase_request_id'] ?? null);
            if (! $pr) {
                return ToolResult::error('Purchase request not found.');
            }
            return $this->ok(app(PurchaseRequestController::class)->show(request(), $pr));
        });
    }
}
