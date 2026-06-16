<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminCustomerController;
use App\Models\User;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminGetCustomerTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_get_customer';
    }

    public function description(): string
    {
        return 'Get a customer\'s profile + stats (total spent, order counts, member since) by id.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('customer_id')->description('The customer (user) id.')->required();

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $customer = User::find($arguments['customer_id'] ?? null);
            if (! $customer) {
                return ToolResult::error('Customer not found.');
            }
            return $this->ok(app(AdminCustomerController::class)->show($customer));
        });
    }
}
