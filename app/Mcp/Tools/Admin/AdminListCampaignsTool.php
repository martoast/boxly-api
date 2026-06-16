<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminCampaignController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminListCampaignsTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_list_campaigns';
    }

    public function description(): string
    {
        return 'List email campaigns with a summary (totals, emails sent). Filter by status or search by name.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('status')->description('Optional status filter.');
        $schema->string('search')->description('Optional search by name.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput([
                'status' => $arguments['status'] ?? null,
                'search' => $arguments['search'] ?? null,
            ]);
            return $this->ok(app(AdminCampaignController::class)->index(request()));
        });
    }
}
