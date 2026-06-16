<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminPurchaseRequestController;
use App\Models\PurchaseRequest;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminGetPurchaseRequestTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_get_purchase_request';
    }

    public function description(): string
    {
        return 'Get full detail of any purchase request by id, including customer, items and quote breakdown.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('purchase_request_id')->description('The purchase request id.')->required();

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $pr = PurchaseRequest::find($arguments['purchase_request_id'] ?? null);
            if (! $pr) {
                return ToolResult::error('Purchase request not found.');
            }
            return $this->ok(app(AdminPurchaseRequestController::class)->show($pr));
        });
    }
}
