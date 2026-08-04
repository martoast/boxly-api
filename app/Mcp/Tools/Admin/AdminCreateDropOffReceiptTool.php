<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\Admin\AdminDropOffReceiptController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminCreateDropOffReceiptTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_create_drop_off_receipt';
    }

    public function description(): string
    {
        return 'Record that a customer physically dropped packages off with Boxly. Give the customer\'s user id (find it with admin_list_customers), what they dropped off as free text, and the date. Creating it does NOT email anyone — use admin_send_drop_off_receipt afterwards, once any photos have been attached from the admin panel. Photos cannot be uploaded over MCP.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('user_id')->description('The customer (user) id who dropped the items off.')->required();
        $schema->string('description')->description('What they dropped off, free text. One item per line reads well — this exact text goes in their receipt email.')->required();
        $schema->string('dropped_off_at')->description('Drop-off date, YYYY-MM-DD. Defaults to today.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput([
                'user_id'        => $arguments['user_id'] ?? null,
                'description'    => $arguments['description'] ?? null,
                'dropped_off_at' => $arguments['dropped_off_at'] ?? null,
            ]);

            return $this->ok(app(AdminDropOffReceiptController::class)->store(request()));
        });
    }
}
