<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminCampaignController;
use App\Models\EmailCampaign;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Schedule a draft campaign — the same pipeline as the admin's "Programar envío" (it sends by itself at that time, boxly:campaigns-scheduled).
 * The day and time are central Mexico time, as the team reads them; `unschedule` takes it off the schedule (it stays a draft).
 */
class AdminScheduleCampaignTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_schedule_campaign';
    }

    public function description(): string
    {
        return 'Schedule a DRAFT email campaign to send by itself on a day and time (central Mexico time, UTC-6), change its time, or unschedule it. Only drafts; the time must be in the future. Nothing is sent now.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->integer('campaign_id')->description('The draft campaign id.')->required();
        $schema->string('day')->description('YYYY-MM-DD in central Mexico time. Required unless unschedule is true.');
        $schema->string('time')->description('HH:MM (24h) in central Mexico time. Defaults to 09:00.');
        $schema->boolean('unschedule')->description('true takes the campaign off the schedule (it stays a draft).');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $campaign = EmailCampaign::find($arguments['campaign_id'] ?? null);
            if (! $campaign) {
                return ToolResult::error('Campaign not found.');
            }
            if (! empty($arguments['unschedule'])) {
                $at = null;
            } else {
                $day = (string) ($arguments['day'] ?? '');
                $time = (string) ($arguments['time'] ?? '09:00');
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || ! preg_match('/^\d{2}:\d{2}$/', $time)) {
                    return ToolResult::error('day must be YYYY-MM-DD and time HH:MM (central Mexico time).');
                }
                $at = "{$day}T{$time}:00-06:00";
            }
            // set directly, not mergeInput (which drops nulls): null is how unschedule is said
            request()->merge(['scheduled_at' => $at]);

            return $this->ok(app(AdminCampaignController::class)->update(request(), $campaign));
        });
    }
}
