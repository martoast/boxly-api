<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\Admin\AdminShoppingTripsController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminListShoppingTripsTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_list_shopping_trips';
    }

    public function description(): string
    {
        return 'List in-person Las Americas shopping trips (the schedule customers book onto). Filter by status or upcoming only.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('status')->description('open | closed | completed.');
        $schema->boolean('upcoming_only')->description('Only future trips.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput([
                'status' => $arguments['status'] ?? null,
                'upcoming_only' => $arguments['upcoming_only'] ?? null,
            ]);
            return $this->ok(app(AdminShoppingTripsController::class)->index(request()));
        });
    }
}
