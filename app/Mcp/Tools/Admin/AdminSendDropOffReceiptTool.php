<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\Admin\AdminDropOffReceiptController;
use App\Models\DropOffReceipt;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminSendDropOffReceiptTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_send_drop_off_receipt';
    }

    public function description(): string
    {
        return 'Email a drop-off receipt to the customer as their confirmation. This sends real mail to a real customer — confirm with the admin first. Safe to run again to resend. Send it only after any photos are attached, since the email includes them.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('receipt_id')->description('The drop-off receipt id to email.')->required();

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $receipt = DropOffReceipt::find($arguments['receipt_id'] ?? null);
            if (! $receipt) {
                return ToolResult::error('Drop-off receipt not found.');
            }

            return $this->ok(app(AdminDropOffReceiptController::class)->sendEmail($receipt));
        });
    }
}
