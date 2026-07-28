<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Adoption tracking for Boxly Shopper — the customer-facing Chrome extension.
 *
 * The extension announces itself to boxly.mx via a window message; the web app
 * relays that here for the signed-in user. So the count is not "downloads" (the
 * Chrome Web Store already reports those) but the far more useful "how many of
 * OUR customers actually have it running" — attributed to real accounts.
 *
 * Not to be confused with ExtensionTokenController, which serves the ADMIN
 * Product Capturer.
 */
class ShopperExtensionController extends Controller
{
    /**
     * Record that the signed-in user has the extension installed.
     *
     * Called at most once a day per browser (the web app throttles), and it is
     * idempotent regardless — installed_at is only ever written once, so a
     * reinstall or a second computer can't inflate the install count.
     */
    public function heartbeat(Request $request)
    {
        $validated = $request->validate([
            'version' => 'nullable|string|max:20',
        ]);

        $user = $request->user();
        $first = $user->shopper_extension_installed_at === null;

        $user->forceFill([
            'shopper_extension_installed_at' => $user->shopper_extension_installed_at ?? now(),
            'shopper_extension_last_seen_at' => now(),
            'shopper_extension_version'      => $validated['version'] ?? $user->shopper_extension_version,
        ])->save();

        return response()->json([
            'success' => true,
            'data'    => [
                'installed_at' => $user->shopper_extension_installed_at,
                'first_time'   => $first,
            ],
        ]);
    }

    /**
     * Adoption stats for the admin CLI / dashboard.
     *
     * "Active" means seen in the last 30 days — the extension pings on any
     * boxly.mx visit, so a stale last_seen means they stopped visiting the site,
     * uninstalled, or switched browsers. We report it as activity, not churn,
     * because we can't tell those apart from here.
     */
    public function stats(Request $request)
    {
        $installed = User::whereNotNull('shopper_extension_installed_at');

        $total = (clone $installed)->count();
        $customers = (clone $installed)->where('role', User::ROLE_CUSTOMER)->count();
        $active30 = (clone $installed)->where('shopper_extension_last_seen_at', '>=', now()->subDays(30))->count();
        $active7 = (clone $installed)->where('shopper_extension_last_seen_at', '>=', now()->subDays(7))->count();
        $installed7 = (clone $installed)->where('shopper_extension_installed_at', '>=', now()->subDays(7))->count();

        // Denominator: customers who could plausibly install it. Everyone is a
        // fair base here — a raw percentage of the whole customer table is the
        // honest read of "how far has this spread".
        $totalCustomers = User::where('role', User::ROLE_CUSTOMER)->count();

        $byVersion = User::whereNotNull('shopper_extension_installed_at')
            ->select('shopper_extension_version as version', DB::raw('count(*) as users'))
            ->groupBy('shopper_extension_version')
            ->orderByDesc('users')
            ->get();

        $recent = User::whereNotNull('shopper_extension_installed_at')
            ->orderByDesc('shopper_extension_installed_at')
            ->limit(20)
            ->get(['id', 'name', 'email', 'shopper_extension_installed_at', 'shopper_extension_last_seen_at', 'shopper_extension_version']);

        return response()->json([
            'success' => true,
            'data'    => [
                'installed'            => $total,
                'installed_customers'  => $customers,
                'total_customers'      => $totalCustomers,
                'adoption_percent'     => $totalCustomers > 0 ? round(($customers / $totalCustomers) * 100, 1) : 0.0,
                'active_last_7_days'   => $active7,
                'active_last_30_days'  => $active30,
                'installed_last_7_days' => $installed7,
                'by_version'           => $byVersion,
                'recent'               => $recent,
            ],
        ]);
    }
}
