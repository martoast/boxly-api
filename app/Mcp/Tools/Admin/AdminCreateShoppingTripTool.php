<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\Admin\AdminShoppingTripsController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminCreateShoppingTripTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_create_shopping_trip';
    }

    public function description(): string
    {
        return 'Open a new in-person Las Americas shopping trip date for customers to book onto.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('trip_date')->description('YYYY-MM-DD.')->required();
        $schema->string('location')->description('Optional location label.');
        $schema->string('start_time')->description('Optional HH:MM.');
        $schema->string('end_time')->description('Optional HH:MM (after start).');
        $schema->string('status')->description('open (default), closed, completed.');
        $schema->string('notes')->description('Optional notes.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput([
                'trip_date' => $arguments['trip_date'] ?? null,
                'location' => $arguments['location'] ?? null,
                'start_time' => $arguments['start_time'] ?? null,
                'end_time' => $arguments['end_time'] ?? null,
                'status' => $arguments['status'] ?? null,
                'notes' => $arguments['notes'] ?? null,
            ]);
            return $this->ok(app(AdminShoppingTripsController::class)->store(request()));
        });
    }
}
