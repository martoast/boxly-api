<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminBusinessExpenseController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminListExpensesTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_list_expenses';
    }

    public function description(): string
    {
        return 'List business expenses. Filter by category, date range or search.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('category')->description('shipping, ads, software, office, po_box, misc.');
        $schema->string('start_date')->description('Optional YYYY-MM-DD.');
        $schema->string('end_date')->description('Optional YYYY-MM-DD.');
        $schema->string('search')->description('Optional search by description/reference.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput([
                'category' => $arguments['category'] ?? null,
                'start_date' => $arguments['start_date'] ?? null,
                'end_date' => $arguments['end_date'] ?? null,
                'search' => $arguments['search'] ?? null,
            ]);
            return $this->ok(app(AdminBusinessExpenseController::class)->index(request()));
        });
    }
}
