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
        return 'Log an expense. scope="business" (default) for company costs; categories shipping, ads, software, office, po_box, misc. scope="personal" for the owners\' own spending; categories rent, food, misc (personal has NO subcategory). Shorthand "expense for Paco/Jesus for <date> for <amount>" = business, category shipping, description = the courier name (Paco and Jesus are the Estafeta drivers). "personal rent 8000" = scope personal, category rent. Amount is MXN.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('scope')->description('business (default) or personal. Business feeds company profit; personal is the owners\' own money, tracked separately.');
        $schema->string('category')->description('Business: shipping, ads, software, office, po_box, misc. Personal: rent, food, misc.')->required();
        $schema->number('amount')->description('Amount in MXN.')->required();
        $schema->string('expense_date')->description('YYYY-MM-DD.')->required();
        $schema->string('payment_method')->description('Optional War Chest account it was paid from: NU, HSBC, or Stripe. When set, that amount is subtracted from that account\'s balance.');
        $schema->string('description')->description('Description (for courier runs, the driver name: Paco or Jesus).');
        $schema->string('reference_number')->description('Optional reference.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput([
                'scope' => $arguments['scope'] ?? null,
                'category' => $arguments['category'] ?? null,
                'amount' => $arguments['amount'] ?? null,
                'expense_date' => $arguments['expense_date'] ?? null,
                'payment_method' => $arguments['payment_method'] ?? null,
                'description' => $arguments['description'] ?? null,
                'reference_number' => $arguments['reference_number'] ?? null,
            ]);
            return $this->ok(app(AdminBusinessExpenseController::class)->store(request()));
        });
    }
}
