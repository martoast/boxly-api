<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\Admin\AdminDropOffReceiptController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminListDropOffReceiptsTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_list_drop_off_receipts';
    }

    public function description(): string
    {
        return 'List drop-off receipts — the records of customers physically handing packages over to Boxly. Search matches receipt number, customer name/email, or the contents text. Filter to one customer with user_id. Each row shows whether the confirmation email has been sent (email_sent_at).';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('search')->description('Optional — matches receipt number, customer name/email, or contents.');
        $schema->integer('user_id')->description('Optional — only this customer\'s drop-offs.');
        $schema->integer('per_page')->description('Optional page size (default 50).');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput([
                'search'   => $arguments['search'] ?? null,
                'user_id'  => $arguments['user_id'] ?? null,
                'per_page' => $arguments['per_page'] ?? null,
            ]);

            return $this->ok(app(AdminDropOffReceiptController::class)->index(request()));
        });
    }
}
