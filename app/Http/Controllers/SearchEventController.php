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
                'type'    => 'required|in:search,product_view,question',
                'query'   => 'nullable|string|max:1000',
                'store'   => 'nullable|string|max:120',
                'title'   => 'nullable|string|max:255',
                'url'     => 'nullable|string|max:2000',
                'results' => 'nullable|integer|min:0',
                'answer'  => 'nullable|string', // for questions: what the assistant replied
            ]);

            $userId = optional(auth('sanctum')->user())->id ?? optional($request->user())->id;

            // For a question we keep the assistant's answer alongside it (in
            // results_sample, the same JSON column searches use) so the export gives
            // analysts the full Q&A pair — just like a search keeps its results.
            $sample = null;
            if (! empty($data['answer'])) {
                $sample = [['answer' => mb_substr(trim($data['answer']), 0, 4000)]];
            }

            SearchEvent::create([
                'user_id'        => $userId,
                'type'           => $data['type'],
                'query'          => isset($data['query']) ? mb_substr(trim($data['query']), 0, 1000) : null,
                'store'          => $data['store'] ?? null,
                'title'          => $data['title'] ?? null,
                'url'            => $data['url'] ?? null,
                'results'        => $data['results'] ?? null,
                'results_sample' => $sample,
            ]);
        } catch (\Throwable $e) {
            // analytics is non-critical — never surface an error to the user
        }

        return response()->json(['success' => true]);
    }

    /**
     * Admin: download ALL AI-search events as CSV (query, the results we served,
     * stores, timestamps) so it can be handed to an AI for analysis. Streamed so
     * it scales to large datasets. days=0 (default) = everything.
     */
    public function export(Request $request)
    {
        $days = max(0, (int) $request->query('days', 0));
        $filename = 'boxly-ai-search-' . Carbon::now()->format('Y-m-d-Hi') . '.csv';

        return response()->streamDownload(function () use ($days) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel renders accents correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'id', 'created_at', 'type', 'query', 'answer', 'results',
                'result_stores', 'result_titles', 'store', 'title', 'url', 'user_id', 'results_json',
            ]);

            $q = SearchEvent::query()->orderBy('id');
            if ($days > 0) {
                $q->where('created_at', '>=', Carbon::now()->subDays($days)->startOfDay());
            }
            $q->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $e) {
                    $sample = is_array($e->results_sample) ? $e->results_sample : [];
                    $answer = collect($sample)->pluck('answer')->filter()->first();
                    fputcsv($out, [
                        $e->id,
                        optional($e->created_at)->toIso8601String(),
                        $e->type,
                        $e->query,
                        $answer,
                        $e->results,
                        collect($sample)->pluck('store')->filter()->unique()->implode(' | '),
                        collect($sample)->pluck('title')->filter()->implode(' | '),
                        $e->store,
                        $e->title,
                        $e->url,
                        $e->user_id,
                        $sample ? json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Normalize a store name ("Walmart - SellerX" → "Walmart") for aggregation. */
    private function normStore(string $s): string
    {
        return trim(explode(' - ', $s)[0]);
    }

    /**
     * Admin: the de-duplicated query corpus (searches + questions) for the period,
     * with counts and type. Feeds the AI "intent map" clustering on the dashboard.
     */
    public function queries(Request $request)
    {
        $days = max(1, min((int) $request->query('days', 30), 365));
        $since = Carbon::now()->subDays($days)->startOfDay();

        try {
            $rows = SearchEvent::where('created_at', '>=', $since)
                ->whereIn('type', [SearchEvent::TYPE_SEARCH, SearchEvent::TYPE_QUESTION])
                ->whereNotNull('query')->where('query', '<>', '')
                ->select('type', 'query', DB::raw('count(*) as c'))
                ->groupBy('type', 'query')
                ->orderByDesc('c')
                ->limit(250)->get()
                ->map(fn ($r) => [
                    'query' => $r->query,
                    'type'  => $r->type,
                    'c'     => (int) $r->c,
                ]);

            return response()->json(['success' => true, 'data' => ['days' => $days, 'queries' => $rows]]);
        } catch (\Throwable $e) {
            return response()->json(['success' => true, 'data' => ['days' => $days, 'queries' => []]]);
        }
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
            $questions = (clone $base)->where('type', SearchEvent::TYPE_QUESTION);

            $totalSearches = (clone $searches)->count();
            $totalViews = (clone $views)->count();
            $totalQuestions = (clone $questions)->count();

            // Quality signals from the data we actually have:
            // avg results per search, and the searches we FAILED (0 results).
            $avgResults = (clone $searches)->whereNotNull('results')->avg('results');
            $zeroResultSearches = (clone $searches)->where('results', 0)->count();
            $guestSearches = (clone $searches)->whereNull('user_id')->count();

            $topQueries = (clone $searches)->whereNotNull('query')->where('query', '<>', '')
                ->select('query', DB::raw('count(*) as c'))
                ->groupBy('query')->orderByDesc('c')->limit(25)->get();

            // Failing queries — searches that returned nothing. Most actionable
            // list on the dashboard: these are the brands/terms we can't yet serve.
            $zeroResultQueries = (clone $searches)->where('results', 0)
                ->whereNotNull('query')->where('query', '<>', '')
                ->select('query', DB::raw('count(*) as c'))
                ->groupBy('query')->orderByDesc('c')->limit(25)->get();

            $topStores = (clone $views)->whereNotNull('store')->where('store', '<>', '')
                ->select('store', DB::raw('count(*) as c'))
                ->groupBy('store')->orderByDesc('c')->limit(25)->get();

            // Most common questions (people often ask the same things) + the
            // failing/repeated terms list mirrors search analytics.
            $topQuestions = (clone $questions)->whereNotNull('query')->where('query', '<>', '')
                ->select('query', DB::raw('count(*) as c'))
                ->groupBy('query')->orderByDesc('c')->limit(25)->get();

            // Recent questions WITH the assistant's answer (stored in results_sample)
            // — the Q&A pairs an admin (or an AI) can review.
            $recentQuestions = (clone $questions)->whereNotNull('query')->where('query', '<>', '')
                ->latest()->limit(40)->get(['query', 'results_sample', 'user_id', 'created_at'])
                ->map(fn ($e) => [
                    'query'      => $e->query,
                    'answer'     => collect($e->results_sample ?? [])->pluck('answer')->filter()->first(),
                    'guest'      => $e->user_id === null,
                    'created_at' => $e->created_at,
                ]);

            $guestQuestions = (clone $questions)->whereNull('user_id')->count();

            $daily = (clone $base)
                ->select(DB::raw('DATE(created_at) as d'), 'type', DB::raw('count(*) as c'))
                ->groupBy('d', 'type')->orderBy('d')->get()
                ->groupBy('d')->map(fn ($rows, $d) => [
                    'date'      => $d,
                    'searches'  => (int) (optional($rows->firstWhere('type', SearchEvent::TYPE_SEARCH))->c ?? 0),
                    'views'     => (int) (optional($rows->firstWhere('type', SearchEvent::TYPE_PRODUCT_VIEW))->c ?? 0),
                    'questions' => (int) (optional($rows->firstWhere('type', SearchEvent::TYPE_QUESTION))->c ?? 0),
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
                'total_questions'        => $totalQuestions,
                'unique_signed_in_users' => $uniqueUsers,
                'guest_searches'         => $guestSearches,
                'guest_questions'        => $guestQuestions,
                'question_guest_rate'    => $totalQuestions ? round($guestQuestions / $totalQuestions * 100, 1) : 0,
                'avg_results'            => round((float) $avgResults, 1),
                'zero_result_searches'   => $zeroResultSearches,
                'zero_result_rate'       => $totalSearches ? round($zeroResultSearches / $totalSearches * 100, 1) : 0,
                'guest_rate'             => $totalSearches ? round($guestSearches / $totalSearches * 100, 1) : 0,
                'purchase_requests'      => $onlinePr,
                'view_rate'              => $totalSearches ? round($totalViews / $totalSearches * 100, 1) : 0,
                'search_to_pr_rate'      => $totalSearches ? round($onlinePr / $totalSearches * 100, 1) : 0,
                'top_queries'            => $topQueries,
                'zero_result_queries'    => $zeroResultQueries,
                'top_questions'          => $topQuestions,
                'recent_questions'       => $recentQuestions,
                'top_stores'             => $topStores,
                'top_result_stores'      => $topResultStores,
                'recent_searches'        => $recentSearches,
                'daily'                  => $daily,
            ]]);
        } catch (\Throwable $e) {
            // Table not migrated yet, etc. — return an empty but valid shape.
            return response()->json(['success' => true, 'data' => [
                'days' => $days, 'total_searches' => 0, 'total_product_views' => 0,
                'total_questions' => 0, 'unique_signed_in_users' => 0, 'guest_searches' => 0,
                'guest_questions' => 0, 'question_guest_rate' => 0, 'avg_results' => 0,
                'zero_result_searches' => 0, 'zero_result_rate' => 0, 'guest_rate' => 0,
                'purchase_requests' => 0, 'view_rate' => 0, 'search_to_pr_rate' => 0,
                'top_queries' => [], 'zero_result_queries' => [], 'top_questions' => [],
                'recent_questions' => [], 'top_stores' => [],
                'top_result_stores' => [], 'recent_searches' => [], 'daily' => [],
                'unavailable' => true,
            ]]);
        }
    }
}
