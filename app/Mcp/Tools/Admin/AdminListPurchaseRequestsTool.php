<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminPurchaseRequestController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminListPurchaseRequestsTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_list_purchase_requests';
    }

    public function description(): string
    {
        return 'List all customer purchase requests (concierge buy-for-me). Filter by status, source (assisted | in_person), or search.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('status')->description('pending_review, quoted, paid, purchased, rejected, cancelled.');
        $schema->string('source')->description('assisted | in_person.');
        $schema->string('search')->description('Optional search by request number or customer.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput([
                'status' => $arguments['status'] ?? null,
                'source' => $arguments['source'] ?? null,
                'search' => $arguments['search'] ?? null,
            ]);
            return $this->ok(app(AdminPurchaseRequestController::class)->index(request()));
        });
    }
}
