<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminPurchaseRequestController;
use App\Models\PurchaseRequest;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminQuotePurchaseRequestTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_quote_purchase_request';
    }

    public function description(): string
    {
        return 'Create/send the Stripe quote for a purchase request. Takes the total actually spent at the US stores (products + store shipping + sales tax, all in) and adds the Boxly commission on top, then invoices the customer. Confirm with the user first.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('purchase_request_id')->description('The purchase request id.')->required();
        $schema->number('amount_spent')->description('Total actually spent at the US stores in USD — products, store shipping and sales tax across every store, as one number.')->required();
        $schema->number('processing_fee_percent')->description('Boxly commission percent (default 15).');
        $schema->string('admin_notes')->description('Optional internal note.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $pr = PurchaseRequest::find($arguments['purchase_request_id'] ?? null);
            if (! $pr) {
                return ToolResult::error('Purchase request not found.');
            }
            $this->mergeInput([
                'amount_spent' => $arguments['amount_spent'] ?? null,
                'processing_fee_percent' => $arguments['processing_fee_percent'] ?? null,
                'admin_notes' => $arguments['admin_notes'] ?? null,
            ]);
            return $this->ok(app(AdminPurchaseRequestController::class)->createQuote(request(), $pr));
        });
    }
}
