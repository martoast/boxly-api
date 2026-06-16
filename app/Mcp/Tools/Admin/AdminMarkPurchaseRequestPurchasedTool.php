<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminPurchaseRequestController;
use App\Models\PurchaseRequest;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminMarkPurchaseRequestPurchasedTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_mark_purchase_request_purchased';
    }

    public function description(): string
    {
        return 'Mark a paid purchase request as purchased — creates the fulfillment order and emails the customer. Confirm with the user first.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('purchase_request_id')->description('The purchase request id (must be paid).')->required();

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $pr = PurchaseRequest::find($arguments['purchase_request_id'] ?? null);
            if (! $pr) {
                return ToolResult::error('Purchase request not found.');
            }
            return $this->ok(app(AdminPurchaseRequestController::class)->markAsPurchased(request(), $pr));
        });
    }
}
