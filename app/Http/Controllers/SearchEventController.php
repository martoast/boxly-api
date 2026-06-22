<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequest;
use App\Models\SearchEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AI-search usage analytics. The frontend logs each search + product view here
 * (best-effort), and the admin dashboard reads aggregates to gauge adoption.
 */
class SearchEventController extends Controller
{
    /**
     * Public, best-effort logging — must NEVER break the search experience, so
     * everything is swallowed (incl. a missing table before the migration runs).
     */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'type'    => 'required|in:search,product_view',
                'query'   => 'nullable|string|max:255',
                'store'   => 'nullable|string|max:120',
                'title'   => 'nullable|string|max:255',
                'url'     => 'nullable|string|max:2000',
                'results' => 'nullable|integer|min:0',
            ]);

            $userId = optional(auth('sanctum')->user())->id ?? optional($request->user())->id;

            SearchEvent::create([
                'user_id' => $userId,
                'type'    => $data['type'],
                'query'   => isset($data['query']) ? mb_substr(trim($data['query']), 0, 255) : null,
                'store'   => $data['store'] ?? null,
                'title'   => $data['title'] ?? null,
                'url'     => $data['url'] ?? null,
                'results' => $data['results'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // analytics is non-critical — never surface an error to the user
        }

        return response()->json(['success' => true]);
    }

    /** Normalize a store name ("Walmart - SellerX" → "Walmart") for aggregation. */
    private function normStore(string $s): string
    {
        return trim(explode(' - ', $s)[0]);
    }

    /** Admin: aggregated AI-search usage for the last N days. */
    public function stats(Request $request)
    {
        $days = max(1, min((int) $request->query('days', 30), 365));
        $since = Carbon::now()->subDays($days)->startOfDay();

        try {
            $base = SearchEvent::where('created_at', '>=', $since);
            $searches = (clone $base)->where('type', SearchEvent::TYPE_SEARCH);
            $views = (clone $base)->where('type', SearchEvent::TYPE_PRODUCT_VIEW);

            $totalSearches = (clone $searches)->count();
            $totalViews = (clone $views)->count();

            $topQueries = (clone $searches)->whereNotNull('query')->where('query', '<>', '')
                ->select('query', DB::raw('count(*) as c'))
                ->groupBy('query')->orderByDesc('c')->limit(25)->get();

            $topStores = (clone $views)->whereNotNull('store')->where('store', '<>', '')
                ->select('store', DB::raw('count(*) as c'))
                ->groupBy('store')->orderByDesc('c')->limit(25)->get();

            $daily = (clone $base)
                ->select(DB::raw('DATE(created_at) as d'), 'type', DB::raw('count(*) as c'))
                ->groupBy('d', 'type')->orderBy('d')->get()
                ->groupBy('d')->map(fn ($rows, $d) => [
                    'date'     => $d,
                    'searches' => (int) (optional($rows->firstWhere('type', SearchEvent::TYPE_SEARCH))->c ?? 0),
                    'views'    => (int) (optional($rows->firstWhere('type', SearchEvent::TYPE_PRODUCT_VIEW))->c ?? 0),
                ])->values();

            $uniqueUsers = (clone $base)->whereNotNull('user_id')->distinct('user_id')->count('user_id');

            // Query → results: the most recent searches with what we served.
            $recentSearches = (clone $searches)->whereNotNull('results')
                ->latest()->limit(30)->get(['query', 'results', 'results_sample', 'created_at'])
                ->map(fn ($e) => [
                    'query'      => $e->query,
                    'results'    => $e->results,
                    'stores'     => collect($e->results_sample ?? [])->pluck('store')
                        ->filter()->map(fn ($s) => $this->normStore($s))->unique()->take(6)->values(),
                    'created_at' => $e->created_at,
                ]);

            // Stores our ALGORITHM returns most (across served results) — compare
            // against top viewed stores to see what's surfaced vs what's clicked.
            $resultStoreCounts = [];
            foreach ((clone $searches)->whereNotNull('results_sample')->latest()->limit(500)->pluck('results_sample') as $sample) {
                foreach (($sample ?? []) as $it) {
                    $s = $it['store'] ?? null;
                    if ($s) {
                        $k = $this->normStore($s);
                        $resultStoreCounts[$k] = ($resultStoreCounts[$k] ?? 0) + 1;
                    }
                }
            }
            arsort($resultStoreCounts);
            $topResultStores = collect($resultStoreCounts)->take(20)
                ->map(fn ($c, $s) => ['store' => $s, 'c' => $c])->values();

            // Conversion proxy: assisted (online) purchase requests in the same window.
            $onlinePr = PurchaseRequest::where('created_at', '>=', $since)
                ->where(fn ($q) => $q->where('source', '<>', 'in_person')->orWhereNull('source'))
                ->count();

            return response()->json(['success' => true, 'data' => [
                'days'                   => $days,
                'total_searches'         => $totalSearches,
                'total_product_views'    => $totalViews,
                'unique_signed_in_users' => $uniqueUsers,
                'purchase_requests'      => $onlinePr,
                'view_rate'              => $totalSearches ? round($totalViews / $totalSearches * 100, 1) : 0,
                'search_to_pr_rate'      => $totalSearches ? round($onlinePr / $totalSearches * 100, 1) : 0,
                'top_queries'            => $topQueries,
                'top_stores'             => $topStores,
                'top_result_stores'      => $topResultStores,
                'recent_searches'        => $recentSearches,
                'daily'                  => $daily,
            ]]);
        } catch (\Throwable $e) {
            // Table not migrated yet, etc. — return an empty but valid shape.
            return response()->json(['success' => true, 'data' => [
                'days' => $days, 'total_searches' => 0, 'total_product_views' => 0,
                'unique_signed_in_users' => 0, 'purchase_requests' => 0, 'view_rate' => 0,
                'search_to_pr_rate' => 0, 'top_queries' => [], 'top_stores' => [],
                'top_result_stores' => [], 'recent_searches' => [], 'daily' => [],
                'unavailable' => true,
            ]]);
        }
    }
}
