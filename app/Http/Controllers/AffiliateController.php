<?php

namespace App\Http\Controllers;

use App\Models\Affiliate;
use App\Models\AffiliateReferral;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AffiliateController extends Controller
{
    /**
     * Validate an affiliate code (public endpoint).
     * Used by frontend to show "Referred by X" message.
     */
    public function validateCode(string $code)
    {
        $affiliate = Affiliate::where('affiliate_code', strtoupper($code))
            ->where('status', Affiliate::STATUS_ACTIVE)
            ->with('user:id,name')
            ->first();

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or inactive affiliate code',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'affiliate_code' => $affiliate->affiliate_code,
                'name' => $affiliate->user->name,
            ],
        ]);
    }

    /**
     * Existing user becomes an affiliate.
     * POST /api/affiliate/become
     */
    public function become(Request $request)
    {
        $user = $request->user();

        // Check if user is already an affiliate
        if ($user->isAffiliate()) {
            return response()->json([
                'success' => false,
                'message' => 'You are already an affiliate',
            ], 400);
        }

        $validated = $request->validate([
            'bank_beneficiary_name' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
        ]);

        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'affiliate_code' => Affiliate::generateCode($user->name),
            'bank_beneficiary_name' => $validated['bank_beneficiary_name'] ?? null,
            'bank_name' => $validated['bank_name'] ?? null,
            'bank_account_number' => $validated['bank_account_number'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'You are now an affiliate!',
            'data' => [
                'affiliate_code' => $affiliate->affiliate_code,
                'referral_link' => $affiliate->referral_link,
                'commission_type' => $affiliate->commission_type,
                'commission_value' => $affiliate->commission_value,
                'status' => $affiliate->status,
            ],
        ], 201);
    }

    /**
     * Get affiliate dashboard stats.
     * GET /api/affiliate/dashboard
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $affiliate = $user->affiliate;

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'message' => 'You are not an affiliate',
            ], 403);
        }

        $totalReferrals = $affiliate->referrals()->count();
        $totalConversions = $affiliate->conversions()->approved()->count();

        return response()->json([
            'success' => true,
            'data' => [
                'affiliate_code' => $affiliate->affiliate_code,
                'referral_link' => $affiliate->referral_link,
                'status' => $affiliate->status,
                'commission_type' => $affiliate->commission_type,
                'commission_value' => $affiliate->commission_value,
                'stats' => [
                    'total_referrals' => $totalReferrals,
                    'total_conversions' => $totalConversions,
                    'total_earnings' => (float) $affiliate->total_earnings,
                    'paid_earnings' => (float) $affiliate->paid_earnings,
                    'pending_earnings' => $affiliate->pending_earnings,
                ],
                'bank_details' => [
                    'beneficiary_name' => $affiliate->bank_beneficiary_name,
                    'bank_name' => $affiliate->bank_name,
                    'account_number' => $affiliate->bank_account_number,
                ],
            ],
        ]);
    }

    /**
     * Get list of referred users.
     * GET /api/affiliate/referrals
     */
    public function referrals(Request $request)
    {
        $user = $request->user();
        $affiliate = $user->affiliate;

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'message' => 'You are not an affiliate',
            ], 403);
        }

        $referrals = $affiliate->referrals()
            ->with(['user:id,name,email,created_at', 'conversions'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $data = $referrals->through(function ($referral) {
            $conversions = $referral->conversions;
            $totalCommission = $conversions->sum('commission_amount');

            return [
                'id' => $referral->id,
                'user' => [
                    'name' => $referral->user->name,
                    'email' => $referral->user->email,
                    'signed_up_at' => $referral->user->created_at,
                ],
                'paid_orders' => $conversions->count(),
                'total_commission' => (float) $totalCommission,
                'has_converted' => $conversions->count() > 0,
                'referred_at' => $referral->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get list of conversions/sales.
     * GET /api/affiliate/conversions
     */
    public function conversions(Request $request)
    {
        $user = $request->user();
        $affiliate = $user->affiliate;

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'message' => 'You are not an affiliate',
            ], 403);
        }

        $conversions = $affiliate->conversions()
            ->with(['order:id,order_number,created_at', 'referral.user:id,name'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $data = $conversions->through(function ($conversion) {
            return [
                'id' => $conversion->id,
                'order_number' => $conversion->order->order_number,
                'order_amount' => (float) $conversion->order_amount,
                'commission_amount' => (float) $conversion->commission_amount,
                'status' => $conversion->status,
                'referred_user' => $conversion->referral->user->name,
                'created_at' => $conversion->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get payout history.
     * GET /api/affiliate/payouts
     */
    public function payouts(Request $request)
    {
        $user = $request->user();
        $affiliate = $user->affiliate;

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'message' => 'You are not an affiliate',
            ], 403);
        }

        $payouts = $affiliate->payouts()
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $data = $payouts->through(function ($payout) {
            return [
                'id' => $payout->id,
                'amount' => (float) $payout->amount,
                'notes' => $payout->notes,
                'paid_at' => $payout->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Update affiliate bank details.
     * PUT /api/affiliate/profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $affiliate = $user->affiliate;

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'message' => 'You are not an affiliate',
            ], 403);
        }

        $validated = $request->validate([
            'bank_beneficiary_name' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
        ]);

        $affiliate->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'bank_beneficiary_name' => $affiliate->bank_beneficiary_name,
                'bank_name' => $affiliate->bank_name,
                'bank_account_number' => $affiliate->bank_account_number,
            ],
        ]);
    }

    /**
     * Track a referral when user registers with a code.
     * This is called internally during user registration.
     */
    public static function trackReferral(int $userId, string $affiliateCode, ?string $ipAddress = null): ?AffiliateReferral
    {
        $affiliate = Affiliate::where('affiliate_code', strtoupper($affiliateCode))
            ->where('status', Affiliate::STATUS_ACTIVE)
            ->first();

        if (!$affiliate) {
            return null;
        }

        // Don't allow self-referral
        if ($affiliate->user_id === $userId) {
            return null;
        }

        // Check if user was already referred
        if (AffiliateReferral::where('user_id', $userId)->exists()) {
            return null;
        }

        return AffiliateReferral::create([
            'affiliate_id' => $affiliate->id,
            'user_id' => $userId,
            'referral_code_used' => $affiliate->affiliate_code,
            'ip_address' => $ipAddress,
        ]);
    }
}
