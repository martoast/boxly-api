<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminCustomerController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminListCustomersTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_list_customers';
    }

    public function description(): string
    {
        return 'List customers. Filter by search (name/email/phone), registration date range, or with/without orders. Use a high limit for "who signed up recently".';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('search')->description('Optional search by name, email or phone.');
        $schema->string('registered_after')->description('Optional YYYY-MM-DD.');
        $schema->string('registered_before')->description('Optional YYYY-MM-DD.');
        $schema->boolean('has_orders')->description('Only customers with orders.');
        $schema->boolean('no_orders')->description('Only customers without orders.');
        $schema->integer('limit')->description('Max results (default 50, max 500).');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput([
                'search' => $arguments['search'] ?? null,
                'registered_after' => $arguments['registered_after'] ?? null,
                'registered_before' => $arguments['registered_before'] ?? null,
                'has_orders' => $arguments['has_orders'] ?? null,
                'no_orders' => $arguments['no_orders'] ?? null,
                'limit' => $arguments['limit'] ?? null,
            ]);
            return $this->ok(app(AdminCustomerController::class)->index(request()));
        });
    }
}
