<?php

namespace App\Http\Controllers;

use App\Models\ShopperExtensionEvent;
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
     * One step of the extension funnel happened.
     *
     * COMPASS §7: "you cannot tune a funnel you cannot see." We knew installs
     * and nothing else — so after building the converting half of the product we
     * could not tell whether a single shopper used it.
     *
     * Deliberately narrow. No URL, no store, no product: the Web Store data
     * disclosure says we do not collect browsing history and §5 treats that as a
     * commitment, not a preference. `localized` is a boolean, never a domain.
     *
     * Fire-and-forget from the extension's point of view — it never blocks the
     * panel and a failure here must never reach the shopper.
     */
    public function event(Request $request)
    {
        $validated = $request->validate([
            'kind' => 'required|string|in:panel_open,gap_shown,box_add,listing_click,autofill',
            'localized' => 'nullable|boolean',
            // A percentage; anything outside 0-100 is a bug upstream, not data.
            'gap_percent' => 'nullable|integer|min:0|max:100',
        ]);

        $user = $request->user();

        ShopperExtensionEvent::create([
            'user_id' => $user->id,
            'kind' => $validated['kind'],
            'localized' => (bool) ($validated['localized'] ?? false),
            'gap_percent' => $validated['gap_percent'] ?? null,
        ]);

        // The extension pings this on real use, so it is also the freshest
        // signal that the install is alive — cheaper than a separate heartbeat.
        $user->forceFill(['shopper_extension_last_seen_at' => now()])->save();

        return response()->json(['success' => true]);
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
                'funnel'               => $this->funnel(),
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

    /**
     * The §7 instrument panel, in one query set.
     *
     * Every number here answers a question the compass asks by name. Where a
     * question can't be answered honestly yet, it is absent rather than
     * approximated.
     */
    private function funnel(): array
    {
        $since = now()->subDays(30);
        $count = fn (string $kind, ?callable $extra = null) => ShopperExtensionEvent::where('kind', $kind)
            ->where('created_at', '>=', $since)
            ->when($extra, $extra)
            ->count();

        $opens = $count('panel_open');
        $localizedOpens = $count('panel_open', fn ($q) => $q->where('localized', true));
        $gaps = $count('gap_shown');
        $adds = $count('box_add');

        // Median, not mean: one 80% outlier should not become the number we put
        // in marketing copy (§8).
        $percents = ShopperExtensionEvent::where('kind', 'gap_shown')
            ->where('created_at', '>=', $since)
            ->whereNotNull('gap_percent')
            ->orderBy('gap_percent')
            ->pluck('gap_percent')
            ->all();
        $n = count($percents);
        $median = $n === 0 ? null : (int) ($n % 2
            ? $percents[intdiv($n, 2)]
            : round(($percents[$n / 2 - 1] + $percents[$n / 2]) / 2));

        return [
            'window_days' => 30,
            'panel_opens' => $opens,
            'panel_opens_localized' => $localizedOpens,
            'gaps_shown' => $gaps,
            'box_adds' => $adds,
            'listing_clicks' => $count('listing_click'),
            'autofills' => $count('autofill'),
            // "Did they ever use it, or just install it?"
            'users_who_opened' => ShopperExtensionEvent::where('kind', 'panel_open')
                ->where('created_at', '>=', $since)->distinct('user_id')->count('user_id'),
            'users_who_added' => ShopperExtensionEvent::where('kind', 'box_add')
                ->where('created_at', '>=', $since)->distinct('user_id')->count('user_id'),
            // "How often do we actually have the news?"
            'gap_rate' => $opens > 0 ? round(($gaps / $opens) * 100, 1) : null,
            // "Is it building shipments or just informing?"
            'add_rate' => $gaps > 0 ? round(($adds / $gaps) * 100, 1) : null,
            'median_gap_percent' => $median,
        ];
    }

    /**
     * Mint the shopper extension's API token.
     *
     * Called by boxly.mx while the customer is signed in (Sanctum cookie), and
     * handed to the extension through the same window.postMessage handshake that
     * already passes their name. The extension needs it to put things in their
     * box — until now it only ever read public data and had no identity.
     *
     * Distinct token name from `chrome-extension` (the admin capturer) so a
     * customer connecting the shopper panel never signs an employee out of the
     * capturer, and vice versa. One active per user: reconnecting replaces it.
     */
    public function token(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $user->tokens()->where('name', 'boxly-shopper')->delete();

        return response()->json([
            'success' => true,
            'data' => ['token' => $user->createToken('boxly-shopper')->plainTextToken],
        ]);
    }
}
