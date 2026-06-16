<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminOrderItemController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminListPackagesTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_list_packages';
    }

    public function description(): string
    {
        return 'List inbound packages (order items) at the warehouse. Filter to pending (not arrived), overdue, arriving soon, missing weight, or search by tracking/name.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('status')->description('arrived | pending.');
        $schema->boolean('overdue')->description('Only overdue packages.');
        $schema->boolean('arriving_soon')->description('Only packages arriving soon.');
        $schema->boolean('missing_weight')->description('Only packages missing a weight.');
        $schema->string('search')->description('Optional search by tracking number or product name.');
        $schema->integer('limit')->description('Max results (default 50, max 500).');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput([
                'status' => $arguments['status'] ?? null,
                'overdue' => $arguments['overdue'] ?? null,
                'arriving_soon' => $arguments['arriving_soon'] ?? null,
                'missing_weight' => $arguments['missing_weight'] ?? null,
                'search' => $arguments['search'] ?? null,
                'limit' => $arguments['limit'] ?? null,
            ]);
            return $this->ok(app(AdminOrderItemController::class)->index(request()));
        });
    }
}
