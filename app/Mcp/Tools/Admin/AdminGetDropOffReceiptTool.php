<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\Admin\AdminDropOffReceiptController;
use App\Models\DropOffReceipt;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminGetDropOffReceiptTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_get_drop_off_receipt';
    }

    public function description(): string
    {
        return 'Get one drop-off receipt in full: customer, drop-off date, what was dropped off, photo URLs, and whether the confirmation email has been sent.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('receipt_id')->description('The drop-off receipt id.')->required();

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $receipt = DropOffReceipt::find($arguments['receipt_id'] ?? null);
            if (! $receipt) {
                return ToolResult::error('Drop-off receipt not found.');
            }

            return $this->ok(app(AdminDropOffReceiptController::class)->show($receipt));
        });
    }
}
