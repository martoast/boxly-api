<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminBusinessExpenseController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminCreateExpenseTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_create_expense';
    }

    public function description(): string
    {
        return 'Log a business expense. Shorthand "expense for Paco/Jesus for <date> for <amount>" = category shipping, description = the courier name (Paco and Jesus are the Estafeta drivers). Amount is MXN.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('category')->description('shipping, ads, software, office, po_box, misc.')->required();
        $schema->number('amount')->description('Amount in MXN.')->required();
        $schema->string('expense_date')->description('YYYY-MM-DD.')->required();
        $schema->string('description')->description('Description (for courier runs, the driver name: Paco or Jesus).');
        $schema->string('reference_number')->description('Optional reference.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput([
                'category' => $arguments['category'] ?? null,
                'amount' => $arguments['amount'] ?? null,
                'expense_date' => $arguments['expense_date'] ?? null,
                'description' => $arguments['description'] ?? null,
                'reference_number' => $arguments['reference_number'] ?? null,
            ]);
            return $this->ok(app(AdminBusinessExpenseController::class)->store(request()));
        });
    }
}
