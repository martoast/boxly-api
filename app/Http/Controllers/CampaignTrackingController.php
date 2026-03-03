<?php

namespace App\Http\Controllers;

use App\Models\CampaignRecipient;
use Illuminate\Http\Request;

class CampaignTrackingController extends Controller
{
    public function pixel(string $token)
    {
        $recipient = CampaignRecipient::where('tracking_token', $token)->first();

        if ($recipient && !$recipient->opened_at) {
            $recipient->update(['opened_at' => now()]);
            $recipient->campaign()->increment('opened_count');
        }

        // Return 1x1 transparent GIF
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($gif, 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => strlen($gif),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function click(string $token)
    {
        $recipient = CampaignRecipient::with('campaign')->where('tracking_token', $token)->first();

        if (!$recipient || !$recipient->campaign->link_url) {
            return redirect(config('app.frontend_url', 'https://boxly.mx'));
        }

        if (!$recipient->opened_at) {
            $recipient->update(['opened_at' => now()]);
            $recipient->campaign()->increment('opened_count');
        }

        if (!$recipient->clicked_at) {
            $recipient->update(['clicked_at' => now()]);
            $recipient->campaign()->increment('clicked_count');
        }

        return redirect($recipient->campaign->link_url);
    }
}
