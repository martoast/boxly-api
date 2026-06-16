<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\UnifiedAdminDashboardController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminDashboardTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_dashboard';
    }

    public function description(): string
    {
        return 'Business dashboard: revenue, orders, customers, packages, financials and urgent attention. Use this for "how are sales going / how is the business doing".';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('period')->description('current | month | year | all. Default current.');
        $schema->integer('month')->description('1-12, required when period=month.');
        $schema->integer('year')->description('Year, e.g. 2026 (for period=month or year).');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput([
                'period' => $arguments['period'] ?? null,
                'month' => $arguments['month'] ?? null,
                'year' => $arguments['year'] ?? null,
            ]);
            return $this->ok(app(UnifiedAdminDashboardController::class)->index(request()));
        });
    }
}
