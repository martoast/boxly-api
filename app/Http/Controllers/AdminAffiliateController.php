<?php

namespace App\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliatePayout;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAffiliateController extends Controller
{
    /**
     * List all affiliates.
     * GET /api/admin/affiliates
     */
    public function index(Request $request)
    {
        $query = Affiliate::with('user:id,name,email,phone');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by pending earnings
        if ($request->boolean('has_pending_earnings')) {
            $query->withPendingEarnings();
        }

        // Search by user name/email or affiliate code
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('affiliate_code', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Get summary totals across ALL affiliates (before pagination)
        $summary = Affiliate::selectRaw('
            COUNT(*) as total_affiliates,
            SUM(total_earnings) as total_earnings,
            SUM(paid_earnings) as total_paid,
            SUM(total_earnings - paid_earnings) as total_pending
        ')->first();

        $totalReferrals = DB::table('affiliate_referrals')->count();
        $totalConversions = DB::table('affiliate_conversions')->count();

        $affiliates = $query->orderBy('created_at', 'desc')->paginate(20);

        $data = $affiliates->through(function ($affiliate) {
            return [
                'id' => $affiliate->id,
                'affiliate_code' => $affiliate->affiliate_code,
                'status' => $affiliate->status,
                'commission_type' => $affiliate->commission_type,
                'commission_value' => (float) $affiliate->commission_value,
                'total_earnings' => (float) $affiliate->total_earnings,
                'paid_earnings' => (float) $affiliate->paid_earnings,
                'pending_earnings' => $affiliate->pending_earnings,
                'referral_count' => $affiliate->referrals()->count(),
                'conversion_count' => $affiliate->conversions()->count(),
                'user' => [
                    'id' => $affiliate->user->id,
                    'name' => $affiliate->user->name,
                    'email' => $affiliate->user->email,
                    'phone' => $affiliate->user->phone,
                ],
                'created_at' => $affiliate->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
            'summary' => [
                'total_affiliates' => (int) $summary->total_affiliates,
                'total_earnings' => (float) ($summary->total_earnings ?? 0),
                'total_paid' => (float) ($summary->total_paid ?? 0),
                'total_pending' => (float) ($summary->total_pending ?? 0),
                'total_referrals' => $totalReferrals,
                'total_conversions' => $totalConversions,
            ],
        ]);
    }

    /**
     * Get a single affiliate with details.
     * GET /api/admin/affiliates/{affiliate}
     */
    public function show(Affiliate $affiliate)
    {
        $affiliate->load('user:id,name,email,phone');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $affiliate->id,
                'affiliate_code' => $affiliate->affiliate_code,
                'referral_link' => $affiliate->referral_link,
                'status' => $affiliate->status,
                'commission_type' => $affiliate->commission_type,
                'commission_value' => (float) $affiliate->commission_value,
                'total_earnings' => (float) $affiliate->total_earnings,
                'paid_earnings' => (float) $affiliate->paid_earnings,
                'pending_earnings' => $affiliate->pending_earnings,
                'bank_details' => [
                    'beneficiary_name' => $affiliate->bank_beneficiary_name,
                    'bank_name' => $affiliate->bank_name,
                    'account_number' => $affiliate->bank_account_number,
                ],
                'stats' => [
                    'referral_count' => $affiliate->referrals()->count(),
                    'conversion_count' => $affiliate->conversions()->count(),
                    'approved_conversions' => $affiliate->conversions()->approved()->count(),
                ],
                'user' => [
                    'id' => $affiliate->user->id,
                    'name' => $affiliate->user->name,
                    'email' => $affiliate->user->email,
                    'phone' => $affiliate->user->phone,
                ],
                'created_at' => $affiliate->created_at,
            ],
        ]);
    }

    /**
     * Create a new affiliate from an existing user.
     * POST /api/admin/affiliates
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'commission_type' => 'sometimes|in:percentage,fixed',
            'commission_value' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:pending,active,suspended',
            'bank_beneficiary_name' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
        ]);

        // Check if user is already an affiliate
        $user = User::find($validated['user_id']);
        if ($user->isAffiliate()) {
            return response()->json([
                'success' => false,
                'message' => 'This user is already an affiliate',
            ], 400);
        }

        $affiliate = Affiliate::create([
            'user_id' => $validated['user_id'],
            'affiliate_code' => Affiliate::generateCode($user->name),
            'commission_type' => $validated['commission_type'] ?? 'percentage',
            'commission_value' => $validated['commission_value'] ?? 10.00,
            'status' => $validated['status'] ?? 'active',
            'bank_beneficiary_name' => $validated['bank_beneficiary_name'] ?? null,
            'bank_name' => $validated['bank_name'] ?? null,
            'bank_account_number' => $validated['bank_account_number'] ?? null,
        ]);

        $affiliate->load('user:id,name,email');

        return response()->json([
            'success' => true,
            'message' => 'Affiliate created successfully',
            'data' => [
                'id' => $affiliate->id,
                'affiliate_code' => $affiliate->affiliate_code,
                'referral_link' => $affiliate->referral_link,
                'status' => $affiliate->status,
                'user' => [
                    'id' => $affiliate->user->id,
                    'name' => $affiliate->user->name,
                    'email' => $affiliate->user->email,
                ],
            ],
        ], 201);
    }

    /**
     * Update an affiliate.
     * PUT /api/admin/affiliates/{affiliate}
     */
    public function update(Request $request, Affiliate $affiliate)
    {
        $validated = $request->validate([
            'commission_type' => 'sometimes|in:percentage,fixed',
            'commission_value' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:pending,active,suspended',
            'bank_beneficiary_name' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
        ]);

        $affiliate->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Affiliate updated successfully',
            'data' => [
                'id' => $affiliate->id,
                'affiliate_code' => $affiliate->affiliate_code,
                'status' => $affiliate->status,
                'commission_type' => $affiliate->commission_type,
                'commission_value' => (float) $affiliate->commission_value,
                'bank_details' => [
                    'beneficiary_name' => $affiliate->bank_beneficiary_name,
                    'bank_name' => $affiliate->bank_name,
                    'account_number' => $affiliate->bank_account_number,
                ],
            ],
        ]);
    }

    /**
     * Delete an affiliate.
     * DELETE /api/admin/affiliates/{affiliate}
     */
    public function destroy(Affiliate $affiliate)
    {
        $affiliate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Affiliate deleted successfully',
        ]);
    }

    /**
     * Get conversions for an affiliate.
     * GET /api/admin/affiliates/{affiliate}/conversions
     */
    public function conversions(Affiliate $affiliate)
    {
        $conversions = $affiliate->conversions()
            ->with(['order:id,order_number,created_at,status', 'referral.user:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $data = $conversions->through(function ($conversion) {
            return [
                'id' => $conversion->id,
                'order' => [
                    'id' => $conversion->order->id,
                    'order_number' => $conversion->order->order_number,
                    'status' => $conversion->order->status,
                    'created_at' => $conversion->order->created_at,
                ],
                'referred_user' => [
                    'id' => $conversion->referral->user->id,
                    'name' => $conversion->referral->user->name,
                    'email' => $conversion->referral->user->email,
                ],
                'order_amount' => (float) $conversion->order_amount,
                'commission_amount' => (float) $conversion->commission_amount,
                'status' => $conversion->status,
                'created_at' => $conversion->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get payout history for an affiliate.
     * GET /api/admin/affiliates/{affiliate}/payouts
     */
    public function payouts(Affiliate $affiliate)
    {
        $payouts = $affiliate->payouts()
            ->with('paidBy:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $data = $payouts->through(function ($payout) {
            return [
                'id' => $payout->id,
                'amount' => (float) $payout->amount,
                'notes' => $payout->notes,
                'paid_by' => [
                    'id' => $payout->paidBy->id,
                    'name' => $payout->paidBy->name,
                ],
                'paid_at' => $payout->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Record a manual payout to an affiliate.
     * POST /api/admin/affiliates/{affiliate}/record-payout
     */
    public function recordPayout(Request $request, Affiliate $affiliate)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Check if amount exceeds pending earnings
        if ($validated['amount'] > $affiliate->pending_earnings) {
            return response()->json([
                'success' => false,
                'message' => 'Payout amount exceeds pending earnings',
                'pending_earnings' => $affiliate->pending_earnings,
            ], 400);
        }

        DB::transaction(function () use ($affiliate, $validated, $request) {
            // Create payout record
            AffiliatePayout::create([
                'affiliate_id' => $affiliate->id,
                'amount' => $validated['amount'],
                'notes' => $validated['notes'] ?? null,
                'paid_by' => $request->user()->id,
            ]);

            // Update affiliate paid_earnings
            $affiliate->increment('paid_earnings', $validated['amount']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Payout recorded successfully',
            'data' => [
                'amount' => (float) $validated['amount'],
                'new_paid_earnings' => (float) $affiliate->fresh()->paid_earnings,
                'new_pending_earnings' => $affiliate->fresh()->pending_earnings,
            ],
        ]);
    }
}
