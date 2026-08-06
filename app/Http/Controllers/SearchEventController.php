<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
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
                'conversation_id' => 'nullable|integer', // the chat thread this happened in
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
                'conversation_id' => $data['conversation_id'] ?? null,
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
                'result_stores', 'result_titles', 'store', 'title', 'url',
                'user_id', 'user_name', 'user_email', 'results_json',
            ]);

            $q = SearchEvent::query()->with('user:id,name,email')->orderBy('id');
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
                        optional($e->user)->name,
                        optional($e->user)->email,
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

        /**
         * ?light=1 — skip the blocks the admin UI does not render.
         *
         * Measured in production: this response is 42.3 KB, and 35 KB of that is
         * `recent_questions` (27 KB) + `recent_searches` (8 KB) — the activity
         * feed, which now pages through /admin/ai-search/events instead and so
         * throws all of it away. Another 5 KB is top_queries / top_questions /
         * top_stores / daily, which nothing on the page draws.
         *
         * OPT-IN, deliberately. `cli/commands/ai-search.js` dumps this endpoint
         * raw, so dropping the fields outright would quietly delete data an
         * analysis session relies on. Without the param the response is exactly
         * what it has always been. Skipped blocks come back as empty collections
         * rather than missing keys, so the shape never changes either.
         */
        $light = $request->boolean('light');

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

            $topQueries = $light ? collect() : (clone $searches)->whereNotNull('query')->where('query', '<>', '')
                ->select('query', DB::raw('count(*) as c'))
                ->groupBy('query')->orderByDesc('c')->limit(25)->get();

            // Failing queries — searches that returned nothing. Most actionable
            // list on the dashboard: these are the brands/terms we can't yet serve.
            $zeroResultQueries = $light ? collect() : (clone $searches)->where('results', 0)
                ->whereNotNull('query')->where('query', '<>', '')
                ->select('query', DB::raw('count(*) as c'))
                ->groupBy('query')->orderByDesc('c')->limit(25)->get();

            $topStores = $light ? collect() : (clone $views)->whereNotNull('store')->where('store', '<>', '')
                ->select('store', DB::raw('count(*) as c'))
                ->groupBy('store')->orderByDesc('c')->limit(25)->get();

            // Most common questions (people often ask the same things) + the
            // failing/repeated terms list mirrors search analytics.
            $topQuestions = $light ? collect() : (clone $questions)->whereNotNull('query')->where('query', '<>', '')
                ->select('query', DB::raw('count(*) as c'))
                ->groupBy('query')->orderByDesc('c')->limit(25)->get();

            // Recent questions WITH the assistant's answer (stored in results_sample)
            // — the Q&A pairs an admin (or an AI) can review.
            $recentQuestions = $light ? collect() : (clone $questions)->whereNotNull('query')->where('query', '<>', '')
                ->with('user:id,name,email,created_at')
                ->latest()->limit(40)->get(['id', 'query', 'results_sample', 'user_id', 'conversation_id', 'created_at'])
                ->map(fn ($e) => [
                    'query'           => $e->query,
                    'answer'          => collect($e->results_sample ?? [])->pluck('answer')->filter()->first(),
                    'guest'           => $e->user_id === null,
                    'user'            => $e->user ? ['name' => $e->user->name, 'email' => $e->user->email, 'created_at' => $e->user->created_at] : null,
                    'conversation_id' => $e->conversation_id,
                    'created_at'      => $e->created_at,
                ]);

            $guestQuestions = (clone $questions)->whereNull('user_id')->count();

            $daily = $light ? collect() : (clone $base)
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
            $recentSearches = $light ? collect() : (clone $searches)->whereNotNull('results')
                ->with('user:id,name,email,created_at')
                ->latest()->limit(30)->get(['id', 'query', 'results', 'results_sample', 'user_id', 'conversation_id', 'created_at'])
                ->map(fn ($e) => [
                    'query'           => $e->query,
                    'results'         => $e->results,
                    'stores'          => collect($e->results_sample ?? [])->pluck('store')
                        ->filter()->map(fn ($s) => $this->normStore($s))->unique()->take(6)->values(),
                    'guest'           => $e->user_id === null,
                    'user'            => $e->user ? ['name' => $e->user->name, 'email' => $e->user->email, 'created_at' => $e->user->created_at] : null,
                    'conversation_id' => $e->conversation_id,
                    'created_at'      => $e->created_at,
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

    /**
     * Admin: a filterable, paginated feed of raw AI-search events (searches,
     * questions, product views) — the granular data behind the dashboard, exposed
     * for the CLI so it can be sliced by customer and date. Every filter is
     * optional and composable.
     *
     * GET /admin/ai-search/events
     *   ?user_id=  exact customer id
     *   ?search=   match the customer's name OR email (LIKE)
     *   ?type=     search | question | product_view
     *   ?query=    match the query text (LIKE)
     *   ?days=     last N days | ?from=YYYY-MM-DD & ?to=YYYY-MM-DD (date range)
     *   ?per_page= (default 50, max 500) & ?page=
     */
    public function events(Request $request)
    {
        $v = $request->validate([
            'user_id'  => 'nullable|integer',
            'search'   => 'nullable|string|max:120',
            // Comma-separated list allowed ("search,question") so a caller can ask
            // for several kinds at once — the admin activity feed wants searches
            // and questions but not product views. A single value still works.
            'type'     => ['nullable', 'string', 'regex:/^(search|question|product_view)(,(search|question|product_view))*$/'],
            'query'    => 'nullable|string|max:200',
            'from'     => 'nullable|date',
            'to'       => 'nullable|date',
            'days'     => 'nullable|integer|min:1|max:3650',
            'per_page' => 'nullable|integer|min:1|max:500',
            'page'     => 'nullable|integer|min:1',
        ]);

        $q = SearchEvent::query()->with('user:id,name,email,created_at');
        $this->applyEventFilters($q, $v);

        $events = $q->latest()->paginate($v['per_page'] ?? 50);
        $events->getCollection()->transform(fn ($e) => [
            'id'              => $e->id,
            'type'            => $e->type,
            'query'           => $e->query,
            'answer'          => collect($e->results_sample ?? [])->pluck('answer')->filter()->first(),
            'results'         => $e->results,
            'stores'          => collect($e->results_sample ?? [])->pluck('store')
                ->filter()->map(fn ($s) => $this->normStore($s))->unique()->take(8)->values(),
            'store'           => $e->store,
            'title'           => $e->title,
            'url'             => $e->url,
            'conversation_id' => $e->conversation_id,
            'guest'           => $e->user_id === null,
            // created_at is the ACCOUNT's creation date, not the event's — the
            // admin feed renders it as "cliente desde …". It was already
            // eager-loaded on the relation and simply never returned.
            'user'            => $e->user ? ['id' => $e->user->id, 'name' => $e->user->name, 'email' => $e->user->email, 'created_at' => $e->user->created_at] : null,
            'created_at'      => $e->created_at,
        ]);

        return response()->json(['success' => true, 'data' => $events]);
    }

    /**
     * Admin: a filterable, paginated list of AI chat threads (conversations) with
     * a message count each — the "which customers had which chats" index for the
     * CLI. Pair a row's id with GET /admin/ai-search/thread/{id} for the full chat.
     *
     * GET /admin/ai-search/conversations  (?user_id / ?search / ?days / ?from / ?to / ?per_page / ?page)
     */
    public function conversations(Request $request)
    {
        $v = $request->validate([
            'user_id'  => 'nullable|integer',
            'search'   => 'nullable|string|max:120',
            'from'     => 'nullable|date',
            'to'       => 'nullable|date',
            'days'     => 'nullable|integer|min:1|max:3650',
            'per_page' => 'nullable|integer|min:1|max:500',
            'page'     => 'nullable|integer|min:1',
        ]);

        $q = Conversation::query()->with('user:id,name,email,created_at')->withCount('messages');
        $this->applyEventFilters($q, $v); // user_id / search / date filters (no type/query)

        $convos = $q->orderByDesc('last_message_at')->paginate($v['per_page'] ?? 50);
        $convos->getCollection()->transform(fn ($c) => [
            'id'              => $c->id,
            'title'           => $c->title,
            'message_count'   => $c->messages_count,
            'user'            => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name, 'email' => $c->user->email] : null,
            'last_message_at' => $c->last_message_at,
            'created_at'      => $c->created_at,
        ]);

        return response()->json(['success' => true, 'data' => $convos]);
    }

    /** Apply the shared customer + date-range filters to a SearchEvent OR Conversation query. */
    private function applyEventFilters($q, array $v): void
    {
        if (! empty($v['user_id'])) {
            $q->where('user_id', $v['user_id']);
        }
        if (! empty($v['search'])) {
            $s = $v['search'];
            $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
        }
        if (! empty($v['type'])) {
            $types = array_values(array_filter(array_map('trim', explode(',', $v['type']))));
            count($types) === 1 ? $q->where('type', $types[0]) : $q->whereIn('type', $types);
        }
        if (! empty($v['query'])) {
            $q->where('query', 'like', '%' . $v['query'] . '%');
        }
        if (! empty($v['days'])) {
            $q->where('created_at', '>=', Carbon::now()->subDays((int) $v['days'])->startOfDay());
        }
        if (! empty($v['from'])) {
            $q->where('created_at', '>=', Carbon::parse($v['from'])->startOfDay());
        }
        if (! empty($v['to'])) {
            $q->where('created_at', '<=', Carbon::parse($v['to'])->endOfDay());
        }
    }

    /**
     * Admin: the FULL chat thread behind a search/question — every message the
     * user exchanged with the AI, so admins can review the whole conversation.
     * Admin-gated by the route group; loads any conversation by id.
     */
    public function thread(Conversation $conversation)
    {
        $conversation->load('user:id,name,email,created_at');

        $messages = $conversation->messages()
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'role', 'content', 'created_at']);

        return response()->json(['success' => true, 'data' => [
            'id'         => $conversation->id,
            'title'      => $conversation->title,
            'created_at' => $conversation->created_at,
            'user'       => $conversation->user
                ? ['id' => $conversation->user->id, 'name' => $conversation->user->name, 'email' => $conversation->user->email, 'created_at' => $conversation->user->created_at]
                : null,
            'messages'   => $messages,
        ]]);
    }
}
