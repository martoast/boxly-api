<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminPurchaseRequestController;
use App\Models\PurchaseRequest;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminRejectPurchaseRequestTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_reject_purchase_request';
    }

    public function description(): string
    {
        return 'Reject a purchase request with a reason (notifies the customer). Confirm with the user first.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('purchase_request_id')->description('The purchase request id.')->required();
        $schema->string('reason')->description('Reason for rejection (shown to the customer).')->required();

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $pr = PurchaseRequest::find($arguments['purchase_request_id'] ?? null);
            if (! $pr) {
                return ToolResult::error('Purchase request not found.');
            }
            $this->mergeInput(['reason' => $arguments['reason'] ?? null]);
            return $this->ok(app(AdminPurchaseRequestController::class)->reject(request(), $pr));
        });
    }
}
