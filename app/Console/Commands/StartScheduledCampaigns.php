<?php

namespace App\Console\Commands;

use App\Http\Controllers\AdminCampaignController;
use App\Models\EmailCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SCHEDULED CAMPAIGNS (Alex 2026-11-17: "schedule the other emails to be sent"): every draft whose scheduled_at has come is started
 * exactly as the admin's Start does (the same controller action: recipients, then batches). A campaign starts once — Start only takes drafts.
 */
class StartScheduledCampaigns extends Command
{
    protected $signature = 'boxly:campaigns-scheduled';

    protected $description = 'Start the draft email campaigns whose scheduled time has come';

    public function handle(AdminCampaignController $controller): int
    {
        $due = EmailCampaign::where('status', EmailCampaign::STATUS_DRAFT)->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->get();
        foreach ($due as $campaign) {
            $r = $controller->start($campaign);
            $ok = $r->getStatusCode() < 300;
            Log::info('scheduled campaign start', ['campaign' => $campaign->id, 'ok' => $ok, 'status' => $r->getStatusCode()]);
            $this->line(($ok ? 'started ' : 'not started ') . $campaign->id . ' — ' . $campaign->name);
            if (! $ok) {
                $campaign->update(['scheduled_at' => null]); // never retried every minute; it stays a draft for the team
            }
        }

        return self::SUCCESS;
    }
}
