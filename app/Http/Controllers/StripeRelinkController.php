<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StripeRelinkController extends Controller
{
    /**
     * One-shot migration helper for switching Stripe accounts: re-point
     * users.stripe_id from OLD-account customer ids to their NEW-account
     * counterparts (created by the customer import, mapped old→new).
     *
     * Called in chunks with {"pairs":[{"old":"cus_x","new":"cus_y"},...]}.
     * Idempotent: a pair whose old id no longer matches any user (already
     * relinked, or the user never existed) simply counts as unmatched.
     * {"dry":true} reports what WOULD change without writing — run that
     * BEFORE the env flip to validate the mapping against production.
     */
    public function relink(Request $request)
    {
        $v = $request->validate([
            'pairs' => 'required|array|min:1|max:500',
            'pairs.*.old' => 'required|string|starts_with:cus_',
            'pairs.*.new' => 'required|string|starts_with:cus_',
            'dry' => 'nullable|boolean',
        ]);
        $dry = (bool) ($v['dry'] ?? false);

        $updated = 0;
        $unmatched = 0;
        foreach ($v['pairs'] as $pair) {
            $query = User::where('stripe_id', $pair['old']);
            if ($dry) {
                $query->exists() ? $updated++ : $unmatched++;
                continue;
            }
            $count = $query->update(['stripe_id' => $pair['new']]);
            $count > 0 ? $updated++ : $unmatched++;
        }

        if (! $dry) {
            Log::info('Stripe relink chunk applied', [
                'updated' => $updated,
                'unmatched' => $unmatched,
            ]);
        }

        return response()->json([
            'success' => true,
            'dry' => $dry,
            'updated' => $updated,
            'unmatched' => $unmatched,
        ]);
    }
}
