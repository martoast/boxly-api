<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use App\Models\CampaignRecipient;
use App\Models\User;
use App\Jobs\ProcessCampaignBatchJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminCampaignController extends Controller
{
    public function index(Request $request)
    {
        $query = EmailCampaign::with('creator:id,name');

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        $campaigns = $query->latest()->paginate(20);

        $summary = [
            'total' => EmailCampaign::count(),
            'active' => EmailCampaign::where('status', EmailCampaign::STATUS_SENDING)->count(),
            'completed' => EmailCampaign::where('status', EmailCampaign::STATUS_COMPLETED)->count(),
            'total_emails_sent' => EmailCampaign::sum('sent_count'),
        ];

        return response()->json([
            'success' => true,
            'data' => $campaigns,
            'summary' => $summary,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'link_text' => ['nullable', 'string', 'max:255'],
            'audience' => ['required', Rule::in([
                EmailCampaign::AUDIENCE_ALL,
                EmailCampaign::AUDIENCE_HAS_ORDERS,
                EmailCampaign::AUDIENCE_NO_ORDERS,
                EmailCampaign::AUDIENCE_ACTIVE_ORDERS,
                EmailCampaign::AUDIENCE_EXPAT,
                EmailCampaign::AUDIENCE_BUSINESS,
                EmailCampaign::AUDIENCE_SHOPPER,
            ])],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'batch_delay_seconds' => ['nullable', 'integer', 'min:10', 'max:3600'],
        ]);

        $campaign = EmailCampaign::create([
            'created_by' => $request->user()->id,
            'name' => $request->name,
            'subject' => $request->subject,
            'body' => $request->body,
            'link_url' => $request->link_url,
            'link_text' => $request->link_text,
            'audience' => $request->audience,
            'batch_size' => $request->batch_size ?? 50,
            'batch_delay_seconds' => $request->batch_delay_seconds ?? 60,
        ]);

        return response()->json([
            'success' => true,
            'data' => $campaign,
            'message' => 'Campaign created successfully',
        ], 201);
    }

    public function show(EmailCampaign $campaign)
    {
        $campaign->load('creator:id,name');
        $campaign->loadCount([
            'recipients as pending_count' => function ($q) { $q->where('status', 'pending'); },
            'recipients as failed_count' => function ($q) { $q->where('status', 'failed'); },
        ]);

        return response()->json([
            'success' => true,
            'data' => $campaign,
        ]);
    }

    public function update(Request $request, EmailCampaign $campaign)
    {
        if ($campaign->status !== EmailCampaign::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft campaigns can be edited',
            ], 422);
        }

        $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'required', 'string'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'link_text' => ['nullable', 'string', 'max:255'],
            'audience' => ['sometimes', 'required', Rule::in([
                EmailCampaign::AUDIENCE_ALL,
                EmailCampaign::AUDIENCE_HAS_ORDERS,
                EmailCampaign::AUDIENCE_NO_ORDERS,
                EmailCampaign::AUDIENCE_ACTIVE_ORDERS,
                EmailCampaign::AUDIENCE_EXPAT,
                EmailCampaign::AUDIENCE_BUSINESS,
                EmailCampaign::AUDIENCE_SHOPPER,
            ])],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'batch_delay_seconds' => ['nullable', 'integer', 'min:10', 'max:3600'],
        ]);

        $campaign->update($request->only([
            'name', 'subject', 'body', 'link_url', 'link_text',
            'audience', 'batch_size', 'batch_delay_seconds',
        ]));

        return response()->json([
            'success' => true,
            'data' => $campaign->fresh(),
            'message' => 'Campaign updated successfully',
        ]);
    }

    public function destroy(EmailCampaign $campaign)
    {
        if (!in_array($campaign->status, [EmailCampaign::STATUS_DRAFT, EmailCampaign::STATUS_CANCELLED])) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft or cancelled campaigns can be deleted',
            ], 422);
        }

        $campaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campaign deleted successfully',
        ]);
    }

    public function start(EmailCampaign $campaign)
    {
        if ($campaign->status !== EmailCampaign::STATUS_DRAFT) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft campaigns can be started',
            ], 422);
        }

        $audienceQuery = $this->buildAudienceQuery($campaign->audience);
        $users = $audienceQuery->get(['id', 'email']);

        if ($users->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No recipients found for this audience',
            ], 422);
        }

        // Create recipient records
        $recipients = $users->map(function ($user) use ($campaign) {
            return [
                'campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'email' => $user->email,
                'status' => CampaignRecipient::STATUS_PENDING,
                'tracking_token' => Str::random(64),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->toArray();

        // Insert in chunks to avoid memory issues
        foreach (array_chunk($recipients, 500) as $chunk) {
            CampaignRecipient::insert($chunk);
        }

        $campaign->update([
            'status' => EmailCampaign::STATUS_SENDING,
            'total_recipients' => count($recipients),
            'started_at' => now(),
        ]);

        ProcessCampaignBatchJob::dispatch($campaign->id);

        Log::info('Campaign started', ['campaign_id' => $campaign->id, 'recipients' => count($recipients)]);

        return response()->json([
            'success' => true,
            'data' => $campaign->fresh(),
            'message' => 'Campaign started with ' . count($recipients) . ' recipients',
        ]);
    }

    public function pause(EmailCampaign $campaign)
    {
        if ($campaign->status !== EmailCampaign::STATUS_SENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Only sending campaigns can be paused',
            ], 422);
        }

        $campaign->update(['status' => EmailCampaign::STATUS_PAUSED]);

        return response()->json([
            'success' => true,
            'data' => $campaign->fresh(),
            'message' => 'Campaign paused',
        ]);
    }

    public function resume(EmailCampaign $campaign)
    {
        if ($campaign->status !== EmailCampaign::STATUS_PAUSED) {
            return response()->json([
                'success' => false,
                'message' => 'Only paused campaigns can be resumed',
            ], 422);
        }

        $campaign->update(['status' => EmailCampaign::STATUS_SENDING]);

        ProcessCampaignBatchJob::dispatch($campaign->id);

        return response()->json([
            'success' => true,
            'data' => $campaign->fresh(),
            'message' => 'Campaign resumed',
        ]);
    }

    public function cancel(EmailCampaign $campaign)
    {
        if (!in_array($campaign->status, [EmailCampaign::STATUS_SENDING, EmailCampaign::STATUS_PAUSED])) {
            return response()->json([
                'success' => false,
                'message' => 'Only sending or paused campaigns can be cancelled',
            ], 422);
        }

        $campaign->update(['status' => EmailCampaign::STATUS_CANCELLED]);

        return response()->json([
            'success' => true,
            'data' => $campaign->fresh(),
            'message' => 'Campaign cancelled',
        ]);
    }

    public function recipients(Request $request, EmailCampaign $campaign)
    {
        $recipients = $campaign->recipients()
            ->with('user:id,name,email')
            ->latest()
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $recipients,
        ]);
    }

    public function previewAudience(Request $request)
    {
        $request->validate([
            'audience' => ['required', Rule::in([
                EmailCampaign::AUDIENCE_ALL,
                EmailCampaign::AUDIENCE_HAS_ORDERS,
                EmailCampaign::AUDIENCE_NO_ORDERS,
                EmailCampaign::AUDIENCE_ACTIVE_ORDERS,
                EmailCampaign::AUDIENCE_EXPAT,
                EmailCampaign::AUDIENCE_BUSINESS,
                EmailCampaign::AUDIENCE_SHOPPER,
            ])],
        ]);

        $count = $this->buildAudienceQuery($request->audience)->count();

        return response()->json([
            'success' => true,
            'data' => ['count' => $count],
        ]);
    }

    public function previewRecipients(Request $request, EmailCampaign $campaign)
    {
        $users = $this->buildAudienceQuery($campaign->audience)
            ->select('users.id', 'users.name', 'users.email', 'users.created_at')
            ->leftJoin('campaign_recipients', function ($join) use ($campaign) {
                $join->on('users.id', '=', 'campaign_recipients.user_id')
                     ->where('campaign_recipients.campaign_id', '=', $campaign->id);
            })
            ->addSelect('campaign_recipients.status as recipient_status')
            ->addSelect('campaign_recipients.sent_at')
            ->latest('users.created_at')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    private function buildAudienceQuery(string $audience)
    {
        $query = User::where('role', 'customer');

        switch ($audience) {
            case EmailCampaign::AUDIENCE_HAS_ORDERS:
                $query->has('orders');
                break;
            case EmailCampaign::AUDIENCE_NO_ORDERS:
                $query->doesntHave('orders');
                break;
            case EmailCampaign::AUDIENCE_ACTIVE_ORDERS:
                $query->has('activeOrders');
                break;
            case EmailCampaign::AUDIENCE_EXPAT:
                $query->where('user_type', User::TYPE_EXPAT);
                break;
            case EmailCampaign::AUDIENCE_BUSINESS:
                $query->where('user_type', User::TYPE_BUSINESS);
                break;
            case EmailCampaign::AUDIENCE_SHOPPER:
                $query->where('user_type', User::TYPE_SHOPPER);
                break;
            // 'all' - no additional filter
        }

        return $query;
    }
}
