<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminCampaignController;
use App\Models\EmailCampaign;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminGetCampaignTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_get_campaign';
    }

    public function description(): string
    {
        return 'Get one email campaign by id: subject, body, button, audience, status, scheduled_at (when a draft sends by itself) and its sending stats.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('campaign_id')->description('The campaign id.')->required();

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $campaign = EmailCampaign::find($arguments['campaign_id'] ?? null);
            if (! $campaign) {
                return ToolResult::error('Campaign not found.');
            }

            return $this->ok(app(AdminCampaignController::class)->show($campaign));
        });
    }
}
