<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminCampaignController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminCampaignCalendarTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_campaign_calendar';
    }

    public function description(): string
    {
        return 'The email campaign calendar between two dates (YYYY-MM-DD): campaigns scheduled (draft with scheduled_at), sending or sent in the range, plus the drafts not scheduled yet. Same data as the admin Calendario view.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('from')->description('First day, YYYY-MM-DD.')->required();
        $schema->string('to')->description('Last day, YYYY-MM-DD.')->required();

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput(['from' => $arguments['from'] ?? null, 'to' => $arguments['to'] ?? null]);

            return $this->ok(app(AdminCampaignController::class)->calendar(request()));
        });
    }
}
