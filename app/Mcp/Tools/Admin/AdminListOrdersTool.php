<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminOrderController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminListOrdersTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_list_orders';
    }

    public function description(): string
    {
        return 'List all customer orders (admin view). Filter by status, search (order/tracking/customer), or date range.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('status')->description('Optional status filter: collecting, awaiting_packages, packages_complete, awaiting_payment, paid, processing, shipped, delivered, cancelled.');
        $schema->string('search')->description('Optional search by order number, tracking, or customer name/email.');
        $schema->string('from_date')->description('Optional start date YYYY-MM-DD.');
        $schema->string('to_date')->description('Optional end date YYYY-MM-DD.');
        $schema->integer('limit')->description('Max results (default 20, max 500).');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput([
                'status' => $arguments['status'] ?? null,
                'search' => $arguments['search'] ?? null,
                'from_date' => $arguments['from_date'] ?? null,
                'to_date' => $arguments['to_date'] ?? null,
                'limit' => $arguments['limit'] ?? null,
            ]);
            return $this->ok(app(AdminOrderController::class)->index(request()));
        });
    }
}
