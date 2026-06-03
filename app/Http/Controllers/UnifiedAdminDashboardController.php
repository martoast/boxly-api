<?php

namespace App\Http\Controllers;

use App\Models\BusinessExpense;
use App\Models\MonthlyManualMetric;
use App\Models\Order;
use App\Models\OrderBox;
use App\Models\OrderItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UnifiedAdminDashboardController extends Controller
{
    /**
     * Get comprehensive admin dashboard data
     * Supports different time periods: current, month, year, all
     * 
     * Query params:
     * - period: current (default) | month | year | all
     * - year: 2025 (defaults to current year)
     * - month: 1-12 (required if period=month)
     */
    public function index(Request $request)
    {
        $request->validate([
            'period' => 'nullable|in:current,month,year,all',
            'year' => 'nullable|integer|min:2020|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $period = $request->get('period', 'current');
        $year = $request->get('year', now()->year);
        $month = $request->get('month', now()->month);

        // Validate month is required for period=month
        if ($period === 'month' && !$request->has('month')) {
            return response()->json([
                'success' => false,
                'message' => 'Month is required when period is "month"'
            ], 400);
        }

        // Build date ranges based on period
        $dateRanges = $this->buildDateRanges($period, $year, $month);

        $data = [
            'period' => [
                'type' => $period,
                'year' => $year,
                'month' => $period === 'month' ? $month : null,
                'month_name' => $period === 'month' ? Carbon::create($year, $month, 1)->format('F') : null,
                'start_date' => $dateRanges['start'],
                'end_date' => $dateRanges['end'],
            ],
            'overview' => $this->getOverview($dateRanges, $period),
            'orders' => $this->getOrdersData($dateRanges),
            'packages' => $this->getPackagesData($dateRanges),
            'financial' => $this->getFinancialData($dateRanges, $year, $month),
            'box_distribution' => $this->getBoxDistribution($dateRanges, $year, $month, $period),
            'activity' => [
                'today' => $this->getTodayActivity(),
                'this_week' => $this->getWeekActivity(),
            ],
            'urgent_attention' => $this->getUrgentItems(),
            'performance' => $this->getPerformanceMetrics(),
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * All-time monthly trajectory for the dashboard charts.
     *
     * Returns one bucket per month from the first month of real activity to
     * now, each holding revenue / expenses / profit / new + cumulative
     * customers / sales count / orders count. Mirrors the same computation as
     * getFinancialData() and respects per-month MonthlyManualMetric overrides
     * (financial + orders) so the charts never contradict the headline cards.
     *
     * GET /admin/dashboard/time-series
     */
    public function timeSeries(Request $request)
    {
        $fx = 18.0; // fixed USD->MXN, same as getFinancialData()

        // --- Grouped aggregations (one query per metric, keyed by 'Y-m') ---
        // Revenue from fully-paid orders, by paid_at month.
        $paidOrders = Order::whereNotNull('paid_at')
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') as ym, SUM(COALESCE(amount_paid, deposit_amount, 0)) as total, COUNT(*) as cnt")
            ->groupBy('ym')->get()->keyBy('ym');

        // Deposit revenue on orders not yet fully paid, by deposit_paid_at month.
        $depositRev = Order::whereNotNull('deposit_paid_at')->whereNull('paid_at')
            ->selectRaw("DATE_FORMAT(deposit_paid_at, '%Y-%m') as ym, SUM(deposit_amount) as total")
            ->groupBy('ym')->get()->keyBy('ym');

        // PR service fees by created_at month, split by currency.
        $prFees = PurchaseRequest::whereIn('status', ['paid', 'purchased'])
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, currency, SUM(processing_fee) as total")
            ->groupBy('ym', 'currency')->get();
        $prFeesByMonth = [];
        foreach ($prFees as $row) {
            $prFeesByMonth[$row->ym] = ($prFeesByMonth[$row->ym] ?? 0)
                + ($row->currency === 'usd' ? $row->total * $fx : $row->total);
        }

        // Sales = count of paid/purchased PRs by created_at month.
        $salesCount = PurchaseRequest::whereIn('status', ['paid', 'purchased'])
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as cnt")
            ->groupBy('ym')->get()->keyBy('ym');

        // Expenses by expense_date month.
        $expenses = BusinessExpense::selectRaw("DATE_FORMAT(expense_date, '%Y-%m') as ym, SUM(amount) as total")
            ->groupBy('ym')->get()->keyBy('ym');

        // New customers by created_at month.
        $newCustomers = User::where('role', 'customer')
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as cnt")
            ->groupBy('ym')->get()->keyBy('ym');

        // Per-month manual overrides.
        $manual = MonthlyManualMetric::all()
            ->keyBy(fn ($m) => sprintf('%04d-%02d', $m->year, $m->month));

        // --- Find the first month with any activity ---
        $firstDates = array_filter([
            Order::whereNotNull('paid_at')->min('paid_at'),
            BusinessExpense::min('expense_date'),
            User::where('role', 'customer')->min('created_at'),
            PurchaseRequest::min('created_at'),
        ]);

        if (empty($firstDates)) {
            return response()->json(['success' => true, 'data' => ['months' => []], 'generated_at' => now()->toIso8601String()]);
        }

        $cursor = Carbon::parse(min($firstDates))->startOfMonth();
        $last = now()->startOfMonth();

        $months = [];
        $cumulativeCustomers = 0;

        while ($cursor->lte($last)) {
            $key = $cursor->format('Y-m');

            $shipping = (float) ($paidOrders[$key]->total ?? 0) + (float) ($depositRev[$key]->total ?? 0);
            $fees = (float) ($prFeesByMonth[$key] ?? 0);
            $calcRevenue = $shipping + $fees;
            $calcExpenses = (float) ($expenses[$key]->total ?? 0);
            $calcOrders = (int) ($paidOrders[$key]->cnt ?? 0);

            // Apply manual overrides (same flags as getFinancialData()).
            $m = $manual[$key] ?? null;
            $isFinancialManual = $m && ($m->is_financial_manual || $m->is_manual_mode);
            $isOrdersManual = $m && ($m->is_orders_manual || $m->is_manual_mode);

            $revenue = $isFinancialManual ? (float) $m->total_revenue : $calcRevenue;
            $monthExpenses = $isFinancialManual ? (float) $m->total_expenses : $calcExpenses;
            $orders = $isOrdersManual ? (int) $m->total_orders : $calcOrders;

            $newCust = (int) ($newCustomers[$key]->cnt ?? 0);
            $cumulativeCustomers += $newCust;

            $months[] = [
                'month' => $key,
                'label' => $cursor->format('M Y'),
                'revenue' => round($revenue, 2),
                'expenses' => round($monthExpenses, 2),
                'profit' => round($revenue - $monthExpenses, 2),
                'new_customers' => $newCust,
                'cumulative_customers' => $cumulativeCustomers,
                'sales_count' => (int) ($salesCount[$key]->cnt ?? 0),
                'orders_count' => $orders,
            ];

            $cursor->addMonth();
        }

        return response()->json([
            'success' => true,
            'data' => ['months' => $months],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    // ============================================================
    // DASHBOARD V3 — Executive Command Center
    // ============================================================

    private const FX = 18.0; // fixed USD->MXN, consistent with the rest of the dashboard

    /**
     * All-time hero + network-scale numbers for Dashboard V3.
     * GET /admin/dashboard/v3/overview
     */
    public function v3Overview(Request $request)
    {
        // Revenue (all-time, MXN) — same sources as getFinancialData()/timeSeries().
        $shipping = (float) (Order::whereNotNull('paid_at')
            ->selectRaw('SUM(COALESCE(amount_paid, deposit_amount, 0)) as t')->value('t') ?? 0);
        $deposits = (float) Order::whereNotNull('deposit_paid_at')->whereNull('paid_at')->sum('deposit_amount');
        $feesUsd = (float) PurchaseRequest::whereIn('status', ['paid', 'purchased'])->where('currency', 'usd')->sum('processing_fee');
        $feesMxn = (float) PurchaseRequest::whereIn('status', ['paid', 'purchased'])->where('currency', 'mxn')->sum('processing_fee');

        // Include manually-entered revenue from months flagged manual (matches all-time card).
        $manualRevenue = (float) MonthlyManualMetric::where('is_manual_mode', true)->sum('total_revenue');

        $revenue = $shipping + $deposits + ($feesUsd * self::FX) + $feesMxn + $manualRevenue;
        $expenses = (float) BusinessExpense::sum('amount');
        $profit = $revenue - $expenses;
        $margin = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0;

        $customers = User::where('role', 'customer')->count();
        $orders = Order::count();
        $packagesReceived = OrderItem::where('arrived', true)->count();

        $statesReached = User::where('role', 'customer')
            ->whereNotNull('estado')->where('estado', '!=', '')
            ->distinct()->count('estado');
        $citiesReached = User::where('role', 'customer')
            ->whereNotNull('municipio')->where('municipio', '!=', '')
            ->distinct()->count('municipio');

        return response()->json([
            'success' => true,
            'data' => [
                'revenue' => round($revenue, 2),
                'expenses' => round($expenses, 2),
                'profit' => round($profit, 2),
                'margin' => $margin,
                'customers' => $customers,
                'orders' => $orders,
                'packages_received' => $packagesReceived,
                'states_reached' => $statesReached,
                'cities_reached' => $citiesReached,
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Revenue time-series for the V3 hero graph, with a range filter.
     * GET /admin/dashboard/v3/revenue-series?range=30d|90d|1y|all
     * Daily buckets for 30d/90d, monthly for 1y/all.
     */
    public function v3RevenueSeries(Request $request)
    {
        $request->validate(['range' => 'nullable|in:30d,90d,1y,all']);
        $range = $request->get('range', '90d');

        $daily = in_array($range, ['30d', '90d'], true);
        $fmt = $daily ? '%Y-%m-%d' : '%Y-%m';

        // Window start.
        if ($range === '30d') {
            $start = now()->copy()->subDays(29)->startOfDay();
        } elseif ($range === '90d') {
            $start = now()->copy()->subDays(89)->startOfDay();
        } elseif ($range === '1y') {
            $start = now()->copy()->subMonths(11)->startOfMonth();
        } else { // all
            $first = array_filter([
                Order::whereNotNull('paid_at')->min('paid_at'),
                PurchaseRequest::whereIn('status', ['paid', 'purchased'])->min('created_at'),
            ]);
            $start = empty($first)
                ? now()->copy()->startOfMonth()
                : Carbon::parse(min($first))->startOfMonth();
        }

        // Aggregations within window, keyed by bucket.
        $orders = Order::whereNotNull('paid_at')->where('paid_at', '>=', $start)
            ->selectRaw("DATE_FORMAT(paid_at, '$fmt') as bk, SUM(COALESCE(amount_paid, deposit_amount, 0)) as total")
            ->groupBy('bk')->get()->keyBy('bk');
        $deposits = Order::whereNotNull('deposit_paid_at')->whereNull('paid_at')->where('deposit_paid_at', '>=', $start)
            ->selectRaw("DATE_FORMAT(deposit_paid_at, '$fmt') as bk, SUM(deposit_amount) as total")
            ->groupBy('bk')->get()->keyBy('bk');
        $prRows = PurchaseRequest::whereIn('status', ['paid', 'purchased'])->where('created_at', '>=', $start)
            ->selectRaw("DATE_FORMAT(created_at, '$fmt') as bk, currency, SUM(processing_fee) as total")
            ->groupBy('bk', 'currency')->get();
        $fees = [];
        foreach ($prRows as $r) {
            $fees[$r->bk] = ($fees[$r->bk] ?? 0) + ($r->currency === 'usd' ? $r->total * self::FX : $r->total);
        }

        // Build zero-filled buckets across the window.
        $points = [];
        $total = 0;
        $cursor = $start->copy();
        $end = now();
        while ($cursor->lte($end)) {
            $key = $cursor->format($daily ? 'Y-m-d' : 'Y-m');
            $val = (float) ($orders[$key]->total ?? 0)
                + (float) ($deposits[$key]->total ?? 0)
                + (float) ($fees[$key] ?? 0);
            $total += $val;
            $points[] = [
                'key' => $key,
                'label' => $daily ? $cursor->format('d M') : $cursor->format('M Y'),
                'revenue' => round($val, 2),
            ];
            $daily ? $cursor->addDay() : $cursor->addMonth();
        }

        return response()->json([
            'success' => true,
            'data' => ['range' => $range, 'total' => round($total, 2), 'points' => $points],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Operations pipeline stage counts for Dashboard V3.
     * Maps Order statuses onto the logistics flow:
     *   San Diego (received) -> Consolidation -> Border Crossing -> Mexico Network -> Delivered
     * GET /admin/dashboard/v3/pipeline
     */
    public function v3Pipeline(Request $request)
    {
        $countByStatus = Order::whereNotIn('status', ['cancelled'])
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')->pluck('cnt', 'status');

        $get = fn (...$statuses) => array_sum(array_map(fn ($s) => (int) ($countByStatus[$s] ?? 0), $statuses));

        $stages = [
            ['key' => 'received',       'count' => $get('awaiting_packages')],
            ['key' => 'consolidating',  'count' => $get('packages_complete', 'awaiting_payment')],
            ['key' => 'ready_to_cross', 'count' => $get('paid')],
            ['key' => 'in_transit',     'count' => $get('shipped')],
            ['key' => 'delivered',      'count' => $get('delivered')],
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'stages' => $stages,
                'packages_received_total' => OrderItem::where('arrived', true)->count(),
                'packages_pending' => OrderItem::where('arrived', false)->count(),
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Auto-generated business highlights for Dashboard V3.
     * GET /admin/dashboard/v3/insights
     */
    public function v3Insights(Request $request)
    {
        $monthlyRevenue = function (Carbon $start, Carbon $end) {
            $shipping = (float) (Order::whereNotNull('paid_at')->whereBetween('paid_at', [$start, $end])
                ->selectRaw('SUM(COALESCE(amount_paid, deposit_amount, 0)) as t')->value('t') ?? 0);
            $deposits = (float) Order::whereNotNull('deposit_paid_at')->whereNull('paid_at')
                ->whereBetween('deposit_paid_at', [$start, $end])->sum('deposit_amount');
            $usd = (float) PurchaseRequest::whereIn('status', ['paid', 'purchased'])->where('currency', 'usd')
                ->whereBetween('created_at', [$start, $end])->sum('processing_fee');
            $mxn = (float) PurchaseRequest::whereIn('status', ['paid', 'purchased'])->where('currency', 'mxn')
                ->whereBetween('created_at', [$start, $end])->sum('processing_fee');
            return $shipping + $deposits + ($usd * self::FX) + $mxn;
        };

        // Revenue growth (this month vs last month).
        $curStart = now()->copy()->startOfMonth();
        $curEnd = now()->copy()->endOfMonth();
        $prevStart = now()->copy()->subMonth()->startOfMonth();
        $prevEnd = now()->copy()->subMonth()->endOfMonth();
        $curRev = $monthlyRevenue($curStart, $curEnd);
        $prevRev = $monthlyRevenue($prevStart, $prevEnd);
        $revenueGrowth = $prevRev > 0 ? round((($curRev - $prevRev) / $prevRev) * 100, 1) : null;

        // Average order value (paid orders).
        $paidQ = Order::whereNotNull('paid_at');
        $paidCount = (clone $paidQ)->count();
        $paidSum = (float) ((clone $paidQ)->selectRaw('SUM(COALESCE(amount_paid, deposit_amount, 0)) as t')->value('t') ?? 0);
        $aov = $paidCount > 0 ? round($paidSum / $paidCount, 2) : 0;

        // Most active market (state by customer count).
        $topMarket = User::where('role', 'customer')
            ->whereNotNull('estado')->where('estado', '!=', '')
            ->selectRaw('estado, COUNT(*) as cnt')->groupBy('estado')
            ->orderByDesc('cnt')->first();

        // Largest box category (new OrderBox + legacy Order.box_size).
        $boxTotals = [];
        foreach (OrderBox::selectRaw('box_size, SUM(quantity) as q')->groupBy('box_size')->get() as $r) {
            $boxTotals[$r->box_size] = ($boxTotals[$r->box_size] ?? 0) + (int) $r->q;
        }
        foreach (Order::whereNotNull('box_size')->selectRaw('box_size, COUNT(*) as q')->groupBy('box_size')->get() as $r) {
            $boxTotals[$r->box_size] = ($boxTotals[$r->box_size] ?? 0) + (int) $r->q;
        }
        arsort($boxTotals);
        $largestBox = empty($boxTotals) ? null : ['size' => array_key_first($boxTotals), 'count' => reset($boxTotals)];

        // Repeat customers' share of revenue.
        $repeatIds = Order::whereNotNull('paid_at')->select('user_id')
            ->groupBy('user_id')->havingRaw('COUNT(*) > 1')->pluck('user_id');
        $repeatRev = $repeatIds->isEmpty() ? 0 : (float) (Order::whereNotNull('paid_at')->whereIn('user_id', $repeatIds)
            ->selectRaw('SUM(COALESCE(amount_paid, deposit_amount, 0)) as t')->value('t') ?? 0);
        $repeatPct = $paidSum > 0 ? round(($repeatRev / $paidSum) * 100, 1) : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'revenue_growth' => $revenueGrowth, // % MoM, null if no prior month
                'aov' => $aov,
                'top_market' => $topMarket ? ['state' => $topMarket->estado, 'customers' => (int) $topMarket->cnt] : null,
                'largest_box' => $largestBox,
                'repeat_revenue_pct' => $repeatPct,
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Geographic reach by Mexican state for Dashboard V3.
     * Aggregates customers, orders and revenue, keyed by the 3-letter state
     * codes used by the @svg-maps/mexico choropleth (agu, jal, cmx, ...).
     * GET /admin/dashboard/v3/geographic
     */
    public function v3Geographic(Request $request)
    {
        $map = self::ESTADO_CODE_MAP;
        $names = self::STATE_NAMES;
        $norm = fn ($s) => \Illuminate\Support\Str::ascii(mb_strtolower(trim((string) $s)));

        $byCode = [];
        $bump = function (&$arr, $code, $field, $val) {
            $arr[$code] ??= ['customers' => 0, 'orders' => 0, 'revenue' => 0];
            $arr[$code][$field] += $val;
        };

        // Customers by state.
        $custRows = User::where('role', 'customer')->whereNotNull('estado')->where('estado', '!=', '')
            ->selectRaw('estado, COUNT(*) as c')->groupBy('estado')->get();
        foreach ($custRows as $r) {
            if ($code = $map[$norm($r->estado)] ?? null) {
                $bump($byCode, $code, 'customers', (int) $r->c);
            }
        }

        // Orders + revenue by the order customer's state.
        $orderRows = Order::join('users', 'orders.user_id', '=', 'users.id')
            ->whereNotIn('orders.status', ['cancelled'])
            ->whereNotNull('users.estado')->where('users.estado', '!=', '')
            ->selectRaw("users.estado as estado, COUNT(*) as o, SUM(CASE WHEN orders.paid_at IS NOT NULL THEN COALESCE(orders.amount_paid, orders.deposit_amount, 0) ELSE 0 END) as rev")
            ->groupBy('users.estado')->get();
        foreach ($orderRows as $r) {
            if ($code = $map[$norm($r->estado)] ?? null) {
                $bump($byCode, $code, 'orders', (int) $r->o);
                $bump($byCode, $code, 'revenue', (float) $r->rev);
            }
        }

        $states = [];
        foreach ($byCode as $code => $v) {
            $states[] = [
                'code' => $code,
                'name' => $names[$code] ?? $code,
                'customers' => (int) $v['customers'],
                'orders' => (int) $v['orders'],
                'revenue' => round($v['revenue'], 2),
            ];
        }

        // --- Cities (municipio) for the Mapbox map points ---
        $byCity = [];
        $cityBump = function (&$arr, $key, $city, $estado, $field, $val) {
            $arr[$key] ??= ['city' => $city, 'estado' => $estado, 'customers' => 0, 'orders' => 0, 'revenue' => 0];
            $arr[$key][$field] += $val;
        };
        $custCity = User::where('role', 'customer')
            ->whereNotNull('municipio')->where('municipio', '!=', '')
            ->selectRaw('municipio, estado, COUNT(*) as c')->groupBy('municipio', 'estado')->get();
        foreach ($custCity as $r) {
            $cityBump($byCity, $norm($r->municipio) . '|' . $norm($r->estado), $r->municipio, $r->estado, 'customers', (int) $r->c);
        }
        $orderCity = Order::join('users', 'orders.user_id', '=', 'users.id')
            ->whereNotIn('orders.status', ['cancelled'])
            ->whereNotNull('users.municipio')->where('users.municipio', '!=', '')
            ->selectRaw("users.municipio as municipio, users.estado as estado, COUNT(*) as o, SUM(CASE WHEN orders.paid_at IS NOT NULL THEN COALESCE(orders.amount_paid, orders.deposit_amount, 0) ELSE 0 END) as rev")
            ->groupBy('users.municipio', 'users.estado')->get();
        foreach ($orderCity as $r) {
            $key = $norm($r->municipio) . '|' . $norm($r->estado);
            $cityBump($byCity, $key, $r->municipio, $r->estado, 'orders', (int) $r->o);
            $cityBump($byCity, $key, $r->municipio, $r->estado, 'revenue', (float) $r->rev);
        }
        $cities = array_map(fn ($v) => [
            'city' => $v['city'],
            'estado' => $v['estado'],
            'customers' => (int) $v['customers'],
            'orders' => (int) $v['orders'],
            'revenue' => round($v['revenue'], 2),
        ], array_values($byCity));

        return response()->json([
            'success' => true,
            'data' => [
                'states' => $states,
                'cities' => $cities,
                'totals' => [
                    'customers' => array_sum(array_column($states, 'customers')),
                    'orders' => array_sum(array_column($states, 'orders')),
                    'revenue' => round(array_sum(array_column($states, 'revenue')), 2),
                    'states_active' => count($states),
                ],
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /** Normalized (Str::ascii + lowercase) estado name => @svg-maps/mexico code. */
    private const ESTADO_CODE_MAP = [
        'aguascalientes' => 'agu',
        'baja california' => 'bcn',
        'baja california sur' => 'bcs',
        'campeche' => 'cam',
        'chiapas' => 'chp',
        'chihuahua' => 'chh',
        'coahuila' => 'coa', 'coahuila de zaragoza' => 'coa',
        'colima' => 'col',
        'durango' => 'dur',
        'guanajuato' => 'gua',
        'guerrero' => 'gro',
        'hidalgo' => 'hid',
        'jalisco' => 'jal',
        'ciudad de mexico' => 'cmx', 'cdmx' => 'cmx', 'distrito federal' => 'cmx', 'mexico city' => 'cmx',
        'mexico' => 'mex', 'estado de mexico' => 'mex', 'edomex' => 'mex', 'state of mexico' => 'mex',
        'michoacan' => 'mic', 'michoacan de ocampo' => 'mic',
        'morelos' => 'mor',
        'nayarit' => 'nay',
        'nuevo leon' => 'nle',
        'oaxaca' => 'oax',
        'puebla' => 'pue',
        'queretaro' => 'que', 'queretaro de arteaga' => 'que',
        'quintana roo' => 'roo',
        'san luis potosi' => 'slp',
        'sinaloa' => 'sin',
        'sonora' => 'son',
        'tabasco' => 'tab',
        'tamaulipas' => 'tam',
        'tlaxcala' => 'tla',
        'veracruz' => 'ver', 'veracruz de ignacio de la llave' => 'ver',
        'yucatan' => 'yuc',
        'zacatecas' => 'zac',
    ];

    /** Code => Spanish display name. */
    private const STATE_NAMES = [
        'agu' => 'Aguascalientes', 'bcn' => 'Baja California', 'bcs' => 'Baja California Sur',
        'cam' => 'Campeche', 'chp' => 'Chiapas', 'chh' => 'Chihuahua', 'coa' => 'Coahuila',
        'col' => 'Colima', 'dur' => 'Durango', 'gua' => 'Guanajuato', 'gro' => 'Guerrero',
        'hid' => 'Hidalgo', 'jal' => 'Jalisco', 'cmx' => 'Ciudad de México', 'mex' => 'Estado de México',
        'mic' => 'Michoacán', 'mor' => 'Morelos', 'nay' => 'Nayarit', 'nle' => 'Nuevo León',
        'oax' => 'Oaxaca', 'pue' => 'Puebla', 'que' => 'Querétaro', 'roo' => 'Quintana Roo',
        'slp' => 'San Luis Potosí', 'sin' => 'Sinaloa', 'son' => 'Sonora', 'tab' => 'Tabasco',
        'tam' => 'Tamaulipas', 'tla' => 'Tlaxcala', 'ver' => 'Veracruz', 'yuc' => 'Yucatán',
        'zac' => 'Zacatecas',
    ];

    /**
     * Update manual metrics for a specific month
     *
     * Supports granular control with individual flags:
     * - is_financial_manual: Control revenue/profit manually
     * - is_boxes_manual: Control box distribution manually
     * - is_orders_manual: Control orders count manually
     *
     * Legacy is_manual_mode is still supported for backward compatibility
     * and will set all granular flags when used.
     */
    public function updateManualMetrics(Request $request)
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
            // Legacy flag (optional now)
            'is_manual_mode' => 'nullable|boolean',
            // Granular flags
            'is_financial_manual' => 'nullable|boolean',
            'is_boxes_manual' => 'nullable|boolean',
            'is_orders_manual' => 'nullable|boolean',
            // Financial values
            'total_revenue' => 'nullable|numeric|min:0',
            'total_expenses' => 'nullable|numeric|min:0',
            'total_profit' => 'nullable|numeric',
            // Orders
            'total_orders' => 'nullable|integer|min:0',
            // Boxes
            'boxes_extra_small' => 'nullable|integer|min:0',
            'boxes_small' => 'nullable|integer|min:0',
            'boxes_medium' => 'nullable|integer|min:0',
            'boxes_large' => 'nullable|integer|min:0',
            'boxes_extra_large' => 'nullable|integer|min:0',
            // Other
            'total_conversations' => 'nullable|integer|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);

        // Set defaults for optional fields
        $validated['total_revenue'] = $validated['total_revenue'] ?? 0;
        $validated['total_expenses'] = $validated['total_expenses'] ?? 0;
        $validated['total_profit'] = $validated['total_profit'] ?? 0;
        $validated['total_orders'] = $validated['total_orders'] ?? 0;
        $validated['boxes_extra_small'] = $validated['boxes_extra_small'] ?? 0;
        $validated['boxes_small'] = $validated['boxes_small'] ?? 0;
        $validated['boxes_medium'] = $validated['boxes_medium'] ?? 0;
        $validated['boxes_large'] = $validated['boxes_large'] ?? 0;
        $validated['boxes_extra_large'] = $validated['boxes_extra_large'] ?? 0;
        $validated['total_conversations'] = $validated['total_conversations'] ?? 0;

        // Handle legacy is_manual_mode: if set to true, enable all granular flags
        if (isset($validated['is_manual_mode']) && $validated['is_manual_mode']) {
            $validated['is_financial_manual'] = $validated['is_financial_manual'] ?? true;
            $validated['is_boxes_manual'] = $validated['is_boxes_manual'] ?? true;
            $validated['is_orders_manual'] = $validated['is_orders_manual'] ?? true;
        }

        // Set defaults for granular flags if not provided
        $validated['is_manual_mode'] = $validated['is_manual_mode'] ?? false;
        $validated['is_financial_manual'] = $validated['is_financial_manual'] ?? false;
        $validated['is_boxes_manual'] = $validated['is_boxes_manual'] ?? false;
        $validated['is_orders_manual'] = $validated['is_orders_manual'] ?? false;

        $metric = MonthlyManualMetric::getOrCreateForMonth(
            $validated['year'],
            $validated['month'],
            $request->user()->id
        );

        $metric->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Manual metrics updated successfully',
            'data' => $metric
        ]);
    }

    /**
     * Get manual metrics for a specific month
     */
    public function getManualMetrics(Request $request)
    {
        $request->validate([
            'year' => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $metric = MonthlyManualMetric::where('year', $request->year)
            ->where('month', $request->month)
            ->with('creator')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $metric
        ]);
    }

    /**
     * Delete manual metrics for a specific month
     * This will make the month revert to automatic calculation
     */
    public function deleteManualMetrics(Request $request)
    {
        $request->validate([
            'year' => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        $deleted = MonthlyManualMetric::where('year', $request->year)
            ->where('month', $request->month)
            ->delete();

        if ($deleted) {
            return response()->json([
                'success' => true,
                'message' => 'Manual metrics deleted successfully. Month will now use automatic calculation.'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No manual metrics found for this month'
        ], 404);
    }

    /**
     * Build date ranges based on period type
     */
    private function buildDateRanges(string $period, int $year, int $month): array
    {
        switch ($period) {
            case 'month':
                $start = Carbon::create($year, $month, 1)->startOfDay();
                $end = Carbon::create($year, $month, 1)->endOfMonth()->endOfDay();
                break;

            case 'year':
                $start = Carbon::create($year, 1, 1)->startOfDay();
                $end = Carbon::create($year, 12, 31)->endOfDay();
                break;

            case 'all':
                // All time - no filtering
                $start = Carbon::create(2020, 1, 1)->startOfDay();
                $end = Carbon::create(2100, 12, 31)->endOfDay();
                break;

            case 'current':
            default:
                // Current means "all time" for overview purposes
                $start = Carbon::create(2020, 1, 1)->startOfDay();
                $end = now()->endOfDay();
                break;
        }

        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
            'period' => $period,
        ];
    }

    /**
     * Get business overview metrics filtered by date range
     * For 'all' period, combines manual + calculated
     */
    private function getOverview(array $dateRanges, string $period): array
    {
        $start = $dateRanges['start'];
        $end = $dateRanges['end'];

        if ($period === 'all') {
            // Get manual metrics where is_manual_mode = true
            $manualMetrics = MonthlyManualMetric::where('is_manual_mode', true)->get();
            $calculatedCustomers = User::where('role', 'customer')->count();

            return [
                'total_customers' => $calculatedCustomers,
                'active_customers' => User::where('role', 'customer')
                    ->whereHas('orders', function ($q) {
                        $q->whereIn('status', [
                            Order::STATUS_COLLECTING,
                            Order::STATUS_AWAITING_PACKAGES,
                            Order::STATUS_PACKAGES_COMPLETE,
                            Order::STATUS_PROCESSING,
                            Order::STATUS_SHIPPED
                        ]);
                    })
                    ->count(),
                'new_customers_this_month' => User::where('role', 'customer')
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count(),
                'total_orders' => $manualMetrics->sum('total_orders') + Order::count(),
                'active_orders' => Order::whereIn('status', [
                    Order::STATUS_COLLECTING,
                    Order::STATUS_AWAITING_PACKAGES,
                    Order::STATUS_PACKAGES_COMPLETE,
                    Order::STATUS_PROCESSING,
                    Order::STATUS_SHIPPED
                ])->count(),
            ];
        }

        return [
            'total_customers' => User::where('role', 'customer')
                ->whereBetween('created_at', [$start, $end])
                ->count(),
            'active_customers' => User::where('role', 'customer')
                ->whereHas('orders', function ($q) use ($start, $end) {
                    $q->whereBetween('created_at', [$start, $end])
                        ->whereIn('status', [
                            Order::STATUS_COLLECTING,
                            Order::STATUS_AWAITING_PACKAGES,
                            Order::STATUS_PACKAGES_COMPLETE,
                            Order::STATUS_PROCESSING,
                            Order::STATUS_SHIPPED
                        ]);
                })
                ->count(),
            'new_customers_this_month' => User::where('role', 'customer')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
            'total_orders' => Order::whereNotNull('paid_at')->whereBetween('paid_at', [$start, $end])->count(),
            'active_orders' => Order::whereBetween('created_at', [$start, $end])
                ->whereIn('status', [
                    Order::STATUS_COLLECTING,
                    Order::STATUS_AWAITING_PACKAGES,
                    Order::STATUS_PACKAGES_COMPLETE,
                    Order::STATUS_PROCESSING,
                    Order::STATUS_SHIPPED
                ])->count(),
        ];
    }

    /**
     * Get orders data breakdown filtered by date range
     */
    private function getOrdersData(array $dateRanges): array
    {
        $start = $dateRanges['start'];
        $end = $dateRanges['end'];

        return [
            'by_status' => [
                'collecting' => Order::whereBetween('created_at', [$start, $end])
                    ->status(Order::STATUS_COLLECTING)->count(),
                'awaiting_packages' => Order::whereBetween('created_at', [$start, $end])
                    ->status(Order::STATUS_AWAITING_PACKAGES)->count(),
                'packages_complete' => Order::whereBetween('created_at', [$start, $end])
                    ->status(Order::STATUS_PACKAGES_COMPLETE)->count(),
                'processing' => Order::whereBetween('created_at', [$start, $end])
                    ->status(Order::STATUS_PROCESSING)->count(),
                'shipped' => Order::whereBetween('created_at', [$start, $end])
                    ->status(Order::STATUS_SHIPPED)->count(),
                'delivered' => Order::whereBetween('created_at', [$start, $end])
                    ->status(Order::STATUS_DELIVERED)->count(),
                'awaiting_payment' => Order::whereBetween('created_at', [$start, $end])
                    ->status(Order::STATUS_AWAITING_PAYMENT)->count(),
                'paid' => Order::whereBetween('created_at', [$start, $end])
                    ->status(Order::STATUS_PAID)->count(),
                'cancelled' => Order::whereBetween('created_at', [$start, $end])
                    ->status(Order::STATUS_CANCELLED)->count(),
            ],
            'ready_for_action' => [
                'ready_to_process' => Order::whereBetween('created_at', [$start, $end])
                    ->status(Order::STATUS_PACKAGES_COMPLETE)->count(),
                'ready_for_invoice' => Order::whereBetween('created_at', [$start, $end])
                    ->status(Order::STATUS_DELIVERED)
                    ->whereNull('quote_sent_at')
                    ->count(),
                'awaiting_payment' => Order::whereBetween('created_at', [$start, $end])
                    ->status(Order::STATUS_AWAITING_PAYMENT)->count(),
                'expired_quotes' => Order::whereBetween('created_at', [$start, $end])
                    ->status(Order::STATUS_AWAITING_PAYMENT)
                    ->where('quote_expires_at', '<', now())
                    ->count(),
            ],
        ];
    }

    /**
     * Get packages/items data filtered by date range
     */
    private function getPackagesData(array $dateRanges): array
    {
        $start = $dateRanges['start'];
        $end = $dateRanges['end'];

        return [
            'total_items' => OrderItem::whereBetween('created_at', [$start, $end])->count(),
            'awaiting_arrival' => OrderItem::whereBetween('created_at', [$start, $end])
                ->where('arrived', false)
                ->whereHas('order', function ($q) {
                    $q->whereIn('status', [
                        Order::STATUS_AWAITING_PACKAGES,
                        Order::STATUS_PACKAGES_COMPLETE
                    ]);
                })
                ->count(),
            'arrived_today' => OrderItem::whereDate('arrived_at', today())->count(),
            'arrived_this_week' => OrderItem::whereBetween('arrived_at', [
                now()->startOfWeek(),
                now()->endOfWeek()
            ])->count(),
            'missing_weight' => OrderItem::whereBetween('created_at', [$start, $end])
                ->where('arrived', true)
                ->whereNull('weight')
                ->count(),
            'expected_today' => OrderItem::whereBetween('created_at', [$start, $end])
                ->where('arrived', false)
                ->whereDate('estimated_delivery_date', today())
                ->count(),
            'expected_this_week' => OrderItem::whereBetween('created_at', [$start, $end])
                ->where('arrived', false)
                ->whereDate('estimated_delivery_date', '>=', today())
                ->whereDate('estimated_delivery_date', '<=', now()->endOfWeek())
                ->count(),
            'overdue' => OrderItem::whereBetween('created_at', [$start, $end])
                ->overdue()->count(),
            'arriving_soon' => OrderItem::whereBetween('created_at', [$start, $end])
                ->arrivingSoon(3)->count(),
        ];
    }

    /**
     * Get comprehensive financial data with manual metrics support
     * Now includes Purchase Request metrics & fees
     */
    private function getFinancialData(array $dateRanges, int $year, int $month): array
    {
        $start = $dateRanges['start'];
        $end = $dateRanges['end'];
        $period = $dateRanges['period'];

        // --- PURCHASE REQUEST METRICS ---
        $purchaseRequestsCount = PurchaseRequest::whereBetween('created_at', [$start, $end])->count();

        // Count items from purchased requests created in this period
        $purchasedItemsCount = PurchaseRequestItem::whereHas('purchaseRequest', function ($q) use ($start, $end) {
            $q->where('status', 'purchased')
                ->whereBetween('created_at', [$start, $end]);
        })->sum('quantity');

        // Service Fee Revenue (processing_fee is our profit from assisted shopping)
        // Use created_at to stay in sync with purchase_requests_count and purchased_items_count
        // Respect currency field: USD fees get converted to MXN, MXN fees stay as-is
        $serviceFeeBaseQuery = PurchaseRequest::whereIn('status', ['paid', 'purchased'])
            ->whereBetween('created_at', [$start, $end]);
        $serviceFeeUSD = (clone $serviceFeeBaseQuery)->where('currency', 'usd')->sum('processing_fee');
        $serviceFeeMXNDirect = (clone $serviceFeeBaseQuery)->where('currency', 'mxn')->sum('processing_fee');
        $serviceFeeMXN = round(($serviceFeeUSD * 18.00) + $serviceFeeMXNDirect, 2);

        // --- SHIPPING METRICS ---
        // Revenue from fully paid orders (includes crossing orders with 100% payment)
        // Use COALESCE to fall back to deposit_amount if amount_paid is null
        $paidOrdersRevenue = Order::whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start, $end])
            ->selectRaw('SUM(COALESCE(amount_paid, deposit_amount, 0)) as total')
            ->value('total') ?? 0;
        // Revenue from deposits on orders not yet fully paid (shipping orders with 50% deposit)
        $pendingDepositRevenue = Order::whereNotNull('deposit_paid_at')
            ->whereNull('paid_at')
            ->whereBetween('deposit_paid_at', [$start, $end])
            ->sum('deposit_amount');
        $shippingRevenue = $paidOrdersRevenue + $pendingDepositRevenue;

        $calculatedTotalRevenue = $shippingRevenue + $serviceFeeMXN;

        // --- CUSTOMERS & EXPENSES (always calculate from DB for reference) ---
        $newCustomers = User::where('role', 'customer')
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $expensesQuery = BusinessExpense::whereBetween('expense_date', [$start, $end]);
        $calculatedExpensesByCategory = [
            'shipping' => round($expensesQuery->clone()->where('category', 'shipping')->sum('amount'), 2),
            'ads' => round($expensesQuery->clone()->where('category', 'ads')->sum('amount'), 2),
            'software' => round($expensesQuery->clone()->where('category', 'software')->sum('amount'), 2),
            'office' => round($expensesQuery->clone()->where('category', 'office')->sum('amount'), 2),
            'po_box' => round($expensesQuery->clone()->where('category', 'po_box')->sum('amount'), 2),
            'misc' => round($expensesQuery->clone()->where('category', 'misc')->sum('amount'), 2),
        ];
        $calculatedTotalExpenses = array_sum($calculatedExpensesByCategory);
        $calculatedExpensesByCategory['total'] = round($calculatedTotalExpenses, 2);
        $calculatedAdSpend = $calculatedExpensesByCategory['ads'];

        // --- ACCOUNTS RECEIVABLE ---
        // Orders with boxes that haven't been fully paid yet (not cancelled)
        $accountsReceivable = $this->calculateAccountsReceivable($start, $end);

        // === ALL TIME MODE ===
        if ($period === 'all') {
            $manualMetrics = MonthlyManualMetric::where('is_manual_mode', true)->get();
            $manualRevenue = $manualMetrics->sum('total_revenue');

            // Revenue from all fully paid orders + pending deposits
            // Use COALESCE to fall back to deposit_amount if amount_paid is null
            $allPaidOrdersRevenue = Order::whereNotNull('paid_at')
                ->selectRaw('SUM(COALESCE(amount_paid, deposit_amount, 0)) as total')
                ->value('total') ?? 0;
            $allPendingDepositRevenue = Order::whereNotNull('deposit_paid_at')
                ->whereNull('paid_at')
                ->sum('deposit_amount');
            $allShippingRevenue = $allPaidOrdersRevenue + $allPendingDepositRevenue;
            $allServiceFeeUSD = PurchaseRequest::whereIn('status', ['paid', 'purchased'])->where('currency', 'usd')->sum('processing_fee');
            $allServiceFeeMXNDirect = PurchaseRequest::whereIn('status', ['paid', 'purchased'])->where('currency', 'mxn')->sum('processing_fee');
            $allServiceFeeMXN = ($allServiceFeeUSD * 18.00) + $allServiceFeeMXNDirect;
            $allCalculatedRevenue = $allShippingRevenue + $allServiceFeeMXN;

            $totalRevenue = $manualRevenue + $allCalculatedRevenue;

            $manualOrders = $manualMetrics->sum('total_orders');
            $calculatedOrders = Order::count();
            $totalOrders = $manualOrders + $calculatedOrders;

            $totalConversations = MonthlyManualMetric::sum('total_conversations');

            $allExpensesQuery = BusinessExpense::query();
            $allTotalExpenses = $allExpensesQuery->sum('amount');
            $allAdSpend = BusinessExpense::where('category', 'ads')->sum('amount');

            $profit = $totalRevenue - $allTotalExpenses;
            $profitMargin = $totalRevenue > 0 ? ($profit / $totalRevenue) * 100 : 0;

            $allCustomers = User::where('role', 'customer')->count();
            $cac = $allCustomers > 0 ? round($allAdSpend / $allCustomers, 2) : 0;
            $roas = $allAdSpend > 0 ? round($totalRevenue / $allAdSpend, 2) : 0;
            $conversionRate = $totalConversations > 0 ? round(($totalOrders / $totalConversations) * 100, 2) : 0;

            $allPurchaseRequestsCount = PurchaseRequest::count();

            $allPurchasedItemsCount = PurchaseRequestItem::whereHas('purchaseRequest', function ($q) {
                $q->where('status', 'purchased');
            })->sum('quantity');

            // Calculate all-time accounts receivable
            $allTimeAR = $this->calculateAccountsReceivable();

            return [
                'source' => 'combined',
                'revenue' => [
                    'period_total' => round($totalRevenue, 2),
                    'manual_portion' => round($manualRevenue, 2),
                    'calculated_portion' => round($allCalculatedRevenue, 2),
                    'total_all_time' => round($totalRevenue, 2),
                    'shipping_revenue' => round($allShippingRevenue, 2),
                    'service_fee_revenue' => round($allServiceFeeMXN, 2),
                ],
                'expenses' => $calculatedExpensesByCategory,
                'profit' => [
                    'amount' => round($profit, 2),
                    'margin' => round($profitMargin, 2),
                ],
                'accounts_receivable' => $allTimeAR,
                'metrics' => [
                    'total_orders' => $totalOrders,
                    'new_customers' => $allCustomers,
                    'total_conversations' => $totalConversations,
                    'cac' => $cac,
                    'roas' => $roas,
                    'conversion_rate' => $conversionRate,
                    'ad_spend' => $allAdSpend,
                    'purchase_requests_count' => $allPurchaseRequestsCount,
                    'purchased_items_count' => $allPurchasedItemsCount,
                ],
                'manual_metrics' => null,
            ];
        }

        // === SPECIFIC MONTH / PERIOD MODE ===
        $manualMetric = null;
        if ($period === 'month') {
            $manualMetric = MonthlyManualMetric::where('year', $year)
                ->where('month', $month)
                ->first();
        }

        // Check granular manual flags (fall back to is_manual_mode for backward compatibility)
        $isFinancialManual = $manualMetric && ($manualMetric->is_financial_manual || $manualMetric->is_manual_mode);
        $isOrdersManual = $manualMetric && ($manualMetric->is_orders_manual || $manualMetric->is_manual_mode);

        // Determine which revenue and expenses to use
        $revenueToUse = $isFinancialManual ? $manualMetric->total_revenue : $calculatedTotalRevenue;
        $expensesToUse = $isFinancialManual ? $manualMetric->total_expenses : $calculatedTotalExpenses;
        $ordersToUse = $isOrdersManual ? $manualMetric->total_orders : Order::whereNotNull('paid_at')->whereBetween('paid_at', [$start, $end])->count();

        // Conversations always come from manual metric if it exists
        $conversations = $manualMetric ? $manualMetric->total_conversations : 0;

        // Calculate profit and metrics using the appropriate revenue AND expenses
        $profit = $revenueToUse - $expensesToUse;
        $profitMargin = $revenueToUse > 0 ? ($profit / $revenueToUse) * 100 : 0;

        // For CAC/ROAS, we need ad spend - use calculated if financial is manual (since we don't track manual ad spend separately)
        // Or we could use the manual expenses as a proxy... but for now, let's keep ad spend from DB
        $adSpendToUse = $calculatedAdSpend;

        $cac = $newCustomers > 0 ? round($adSpendToUse / $newCustomers, 2) : 0;
        $roas = $adSpendToUse > 0 ? round($revenueToUse / $adSpendToUse, 2) : 0;
        $conversionRate = $conversations > 0 ? round(($ordersToUse / $conversations) * 100, 2) : 0;

        // Determine source based on what's manual
        $source = 'calculated';
        if ($isFinancialManual && $isOrdersManual) {
            $source = 'manual';
        } elseif ($isFinancialManual || $isOrdersManual) {
            $source = 'mixed';
        }

        // Period Breakdowns (always calculated from DB)
        // Helper to get processing fees using created_at for consistency
        // Respects currency: USD fees converted to MXN, MXN fees stay as-is
        $getPurchaseRequestFees = function ($dateCallback) {
            $baseQuery = PurchaseRequest::whereIn('status', ['paid', 'purchased']);
            $dateCallback($baseQuery, 'created_at');
            $usdFees = (clone $baseQuery)->where('currency', 'usd')->sum('processing_fee');
            $mxnFees = (clone $baseQuery)->where('currency', 'mxn')->sum('processing_fee');
            return ($usdFees * 18) + $mxnFees;
        };

        $todayShipping = Order::whereDate('paid_at', today())->sum('amount_paid');
        $todayFees = $getPurchaseRequestFees(fn($q, $col) => $q->whereDate($col, today()));
        $todayRevenue = $todayShipping + $todayFees;

        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();
        $weekShipping = Order::whereBetween('paid_at', [$weekStart, $weekEnd])->sum('amount_paid');
        $weekFees = $getPurchaseRequestFees(fn($q, $col) => $q->whereBetween($col, [$weekStart, $weekEnd]));
        $weekRevenue = $weekShipping + $weekFees;

        $currentMonth = now()->month;
        $currentYear = now()->year;
        $monthShipping = Order::whereMonth('paid_at', $currentMonth)->whereYear('paid_at', $currentYear)->sum('amount_paid');
        $monthFees = $getPurchaseRequestFees(fn($q, $col) => $q->whereMonth($col, $currentMonth)->whereYear($col, $currentYear));
        $monthRevenue = $monthShipping + $monthFees;

        $allTimeUsdFees = PurchaseRequest::whereIn('status', ['paid', 'purchased'])->where('currency', 'usd')->sum('processing_fee');
        $allTimeMxnFees = PurchaseRequest::whereIn('status', ['paid', 'purchased'])->where('currency', 'mxn')->sum('processing_fee');
        $totalRevenueAllTime = Order::sum('amount_paid') + ($allTimeUsdFees * 18) + $allTimeMxnFees;

        // Build expenses response - show manual total if financial is manual, but always include calculated breakdown
        $expensesResponse = $calculatedExpensesByCategory;
        if ($isFinancialManual) {
            $expensesResponse['total'] = round($expensesToUse, 2);
            $expensesResponse['is_manual'] = true;
            $expensesResponse['calculated_total'] = round($calculatedTotalExpenses, 2);
        }

        return [
            'source' => $source,
            'revenue' => [
                'period_total' => round($revenueToUse, 2),
                'is_manual' => $isFinancialManual,
                'calculated_value' => round($calculatedTotalRevenue, 2),
                'today' => round($todayRevenue, 2),
                'this_week' => round($weekRevenue, 2),
                'this_month' => round($monthRevenue, 2),
                'total_all_time' => round($totalRevenueAllTime, 2),
                'breakdown' => [
                    'shipping' => round($shippingRevenue, 2),
                    'service_fees' => round($serviceFeeMXN, 2),
                ]
            ],
            'expenses' => $expensesResponse,
            'profit' => [
                'amount' => round($profit, 2),
                'margin' => round($profitMargin, 2),
            ],
            'accounts_receivable' => $accountsReceivable,
            'metrics' => [
                'total_orders' => $ordersToUse,
                'total_orders_is_manual' => $isOrdersManual,
                'total_orders_calculated' => Order::whereNotNull('paid_at')->whereBetween('paid_at', [$start, $end])->count(),
                'new_customers' => $newCustomers,
                'total_conversations' => $conversations,
                'cac' => $cac,
                'roas' => $roas,
                'conversion_rate' => $conversionRate,
                'ad_spend' => $adSpendToUse,
                'purchase_requests_count' => $purchaseRequestsCount,
                'purchased_items_count' => $purchasedItemsCount,
            ],
            'manual_metrics' => $manualMetric ? [
                'id' => $manualMetric->id,
                'is_manual_mode' => $manualMetric->is_manual_mode,
                'is_financial_manual' => $manualMetric->is_financial_manual,
                'is_orders_manual' => $manualMetric->is_orders_manual,
                'is_boxes_manual' => $manualMetric->is_boxes_manual,
                'notes' => $manualMetric->notes,
                'last_updated' => $manualMetric->updated_at,
            ] : null,
        ];
    }

    /**
     * Get box size distribution with manual metrics support.
     * Now counts boxes from both the new order_boxes table AND legacy orders.box_size field.
     * Each box's quantity is properly counted (e.g., 2x Medium = 2 medium boxes).
     */
    private function getBoxDistribution(array $dateRanges, int $year, int $month, string $period): array
    {
        $start = $dateRanges['start'];
        $end = $dateRanges['end'];

        if ($period === 'all') {
            $manualMetrics = MonthlyManualMetric::where('is_manual_mode', true)->get();

            $manualBoxes = [
                'extra-small' => $manualMetrics->sum('boxes_extra_small'),
                'small' => $manualMetrics->sum('boxes_small'),
                'medium' => $manualMetrics->sum('boxes_medium'),
                'large' => $manualMetrics->sum('boxes_large'),
                'extra-large' => $manualMetrics->sum('boxes_extra_large'),
            ];

            // Count boxes from the new order_boxes table (sum of quantities by size)
            $newBoxCounts = $this->getBoxCountsFromOrderBoxes();

            // Count legacy boxes from orders that don't have entries in order_boxes
            $legacyBoxCounts = $this->getLegacyBoxCounts();

            $calculatedBoxes = [
                'extra-small' => $newBoxCounts['extra-small'] + $legacyBoxCounts['extra-small'],
                'small' => $newBoxCounts['small'] + $legacyBoxCounts['small'],
                'medium' => $newBoxCounts['medium'] + $legacyBoxCounts['medium'],
                'large' => $newBoxCounts['large'] + $legacyBoxCounts['large'],
                'extra-large' => $newBoxCounts['extra-large'] + $legacyBoxCounts['extra-large'],
            ];

            $totalBoxes = [
                'extra-small' => $manualBoxes['extra-small'] + $calculatedBoxes['extra-small'],
                'small' => $manualBoxes['small'] + $calculatedBoxes['small'],
                'medium' => $manualBoxes['medium'] + $calculatedBoxes['medium'],
                'large' => $manualBoxes['large'] + $calculatedBoxes['large'],
                'extra-large' => $manualBoxes['extra-large'] + $calculatedBoxes['extra-large'],
            ];

            // Count orders without any box selection
            $ordersWithNewBoxes = OrderBox::distinct('order_id')->pluck('order_id');
            $notSelected = Order::whereNull('box_size')
                ->whereNotIn('id', $ordersWithNewBoxes)
                ->count();

            return [
                'source' => 'combined',
                'extra-small' => $totalBoxes['extra-small'],
                'small' => $totalBoxes['small'],
                'medium' => $totalBoxes['medium'],
                'large' => $totalBoxes['large'],
                'extra-large' => $totalBoxes['extra-large'],
                'not_selected' => $notSelected,
                'total' => array_sum($totalBoxes),
            ];
        }

        $manualMetric = MonthlyManualMetric::where('year', $year)
            ->where('month', $month)
            ->first();

        // Check granular flag (fall back to is_manual_mode for backward compatibility)
        $isBoxesManual = $manualMetric && ($manualMetric->is_boxes_manual || $manualMetric->is_manual_mode);

        // Always calculate the DB values for reference
        $newBoxCounts = $this->getBoxCountsFromOrderBoxes($start, $end);
        $legacyBoxCounts = $this->getLegacyBoxCounts($start, $end);

        $calculatedBoxes = [
            'extra-small' => $newBoxCounts['extra-small'] + $legacyBoxCounts['extra-small'],
            'small' => $newBoxCounts['small'] + $legacyBoxCounts['small'],
            'medium' => $newBoxCounts['medium'] + $legacyBoxCounts['medium'],
            'large' => $newBoxCounts['large'] + $legacyBoxCounts['large'],
            'extra-large' => $newBoxCounts['extra-large'] + $legacyBoxCounts['extra-large'],
        ];

        $ordersWithNewBoxes = OrderBox::whereHas('order', function ($q) use ($start, $end) {
            $q->whereNotNull('paid_at')->whereBetween('paid_at', [$start, $end]);
        })->distinct('order_id')->pluck('order_id');

        $notSelected = Order::whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start, $end])
            ->whereNull('box_size')
            ->whereNotIn('id', $ordersWithNewBoxes)
            ->count();

        if ($isBoxesManual) {
            return [
                'source' => 'manual',
                'is_manual' => true,
                'extra-small' => $manualMetric->boxes_extra_small,
                'small' => $manualMetric->boxes_small,
                'medium' => $manualMetric->boxes_medium,
                'large' => $manualMetric->boxes_large,
                'extra-large' => $manualMetric->boxes_extra_large,
                'not_selected' => 0,
                'total' => $manualMetric->total_boxes,
                'calculated' => [
                    'extra-small' => $calculatedBoxes['extra-small'],
                    'small' => $calculatedBoxes['small'],
                    'medium' => $calculatedBoxes['medium'],
                    'large' => $calculatedBoxes['large'],
                    'extra-large' => $calculatedBoxes['extra-large'],
                    'not_selected' => $notSelected,
                    'total' => array_sum($calculatedBoxes),
                ],
            ];
        }

        return [
            'source' => 'calculated',
            'is_manual' => false,
            'extra-small' => $calculatedBoxes['extra-small'],
            'small' => $calculatedBoxes['small'],
            'medium' => $calculatedBoxes['medium'],
            'large' => $calculatedBoxes['large'],
            'extra-large' => $calculatedBoxes['extra-large'],
            'not_selected' => $notSelected,
            'total' => array_sum($calculatedBoxes),
        ];
    }

    /**
     * Get box counts from the new order_boxes table.
     * Sums quantities by box_size.
     */
    private function getBoxCountsFromOrderBoxes(?string $start = null, ?string $end = null): array
    {
        $query = OrderBox::query();

        if ($start && $end) {
            $query->whereHas('order', function ($q) use ($start, $end) {
                $q->whereNotNull('paid_at')->whereBetween('paid_at', [$start, $end]);
            });
        }

        $counts = $query->select('box_size', DB::raw('SUM(quantity) as total'))
            ->groupBy('box_size')
            ->pluck('total', 'box_size')
            ->toArray();

        return [
            'extra-small' => (int) ($counts['extra-small'] ?? 0),
            'small' => (int) ($counts['small'] ?? 0),
            'medium' => (int) ($counts['medium'] ?? 0),
            'large' => (int) ($counts['large'] ?? 0),
            'extra-large' => (int) ($counts['extra-large'] ?? 0),
        ];
    }

    /**
     * Get box counts from legacy orders.box_size field.
     * Only counts orders that DON'T have entries in order_boxes table.
     */
    private function getLegacyBoxCounts(?string $start = null, ?string $end = null): array
    {
        // Get order IDs that have entries in the new order_boxes table
        $ordersWithNewBoxes = OrderBox::distinct('order_id')->pluck('order_id');

        $query = Order::whereNotNull('box_size')
            ->whereNotIn('id', $ordersWithNewBoxes);

        if ($start && $end) {
            $query->whereNotNull('paid_at')->whereBetween('paid_at', [$start, $end]);
        }

        $counts = $query->select('box_size', DB::raw('COUNT(*) as total'))
            ->groupBy('box_size')
            ->pluck('total', 'box_size')
            ->toArray();

        return [
            'extra-small' => (int) ($counts['extra-small'] ?? 0),
            'small' => (int) ($counts['small'] ?? 0),
            'medium' => (int) ($counts['medium'] ?? 0),
            'large' => (int) ($counts['large'] ?? 0),
            'extra-large' => (int) ($counts['extra-large'] ?? 0),
        ];
    }

    /**
     * Get today's activity
     */
    private function getTodayActivity(): array
    {
        return [
            'orders_created' => Order::whereDate('created_at', today())->count(),
            'orders_completed' => Order::whereDate('completed_at', today())->count(),
            'packages_arrived' => OrderItem::whereDate('arrived_at', today())->count(),
            'invoices_sent' => Order::whereDate('quote_sent_at', today())->count(),
            'payments_received' => Order::whereDate('paid_at', today())->count(),
            'orders_shipped' => Order::whereDate('shipped_at', today())->count(),
            'orders_delivered' => Order::whereDate('delivered_at', today())->count(),
            'revenue' => round(Order::whereDate('paid_at', today())->sum('amount_paid'), 2),
        ];
    }

    /**
     * Get this week's activity
     */
    private function getWeekActivity(): array
    {
        $start = now()->startOfWeek();
        $end = now()->endOfWeek();

        return [
            'orders_created' => Order::whereBetween('created_at', [$start, $end])->count(),
            'packages_arrived' => OrderItem::whereBetween('arrived_at', [$start, $end])->count(),
            'invoices_sent' => Order::whereBetween('quote_sent_at', [$start, $end])->count(),
            'payments_received' => Order::whereBetween('paid_at', [$start, $end])->count(),
            'orders_shipped' => Order::whereBetween('shipped_at', [$start, $end])->count(),
            'revenue' => round(Order::whereBetween('paid_at', [$start, $end])->sum('amount_paid'), 2),
        ];
    }

    /**
     * Get items requiring urgent attention
     */
    private function getUrgentItems(): array
    {
        return [
            'overdue_packages' => OrderItem::with(['order.user'])
                ->overdue()
                ->limit(10)
                ->get()
                ->map(fn($item) => [
                    'id' => $item->id,
                    'order_number' => $item->order->order_number,
                    'customer_name' => $item->order->user->name,
                    'product_name' => $item->product_name,
                    'estimated_delivery_date' => $item->estimated_delivery_date,
                    'days_overdue' => now()->diffInDays($item->estimated_delivery_date),
                ]),
            'expired_invoices' => Order::with('user')
                ->status(Order::STATUS_AWAITING_PAYMENT)
                ->where('quote_expires_at', '<', now())
                ->limit(10)
                ->get()
                ->map(fn($order) => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->user->name,
                    'quoted_amount' => $order->quoted_amount,
                    'expired_at' => $order->quote_expires_at,
                    'days_expired' => now()->diffInDays($order->quote_expires_at),
                ]),
        ];
    }

    /**
     * Get performance metrics
     */
    private function getPerformanceMetrics(): array
    {
        return [
            'average_processing_time_days' => round(
                Order::whereNotNull('processing_started_at')
                    ->whereNotNull('shipped_at')
                    ->selectRaw('AVG(DATEDIFF(shipped_at, processing_started_at)) as avg_days')
                    ->value('avg_days') ?? 0,
                1
            ),
            'average_delivery_time_days' => round(
                Order::whereNotNull('shipped_at')
                    ->whereNotNull('delivered_at')
                    ->selectRaw('AVG(DATEDIFF(delivered_at, shipped_at)) as avg_days')
                    ->value('avg_days') ?? 0,
                1
            ),
            'average_items_per_order' => round(
                OrderItem::selectRaw('COUNT(*) / COUNT(DISTINCT order_id) as avg_items')
                    ->value('avg_items') ?? 0,
                1
            ),
            'average_weight_per_order_kg' => round(
                Order::whereNotNull('total_weight')->avg('total_weight') ?? 0,
                2
            ),
        ];
    }

    /**
     * Calculate accounts receivable - unpaid orders that have boxes.
     * These are orders where customers owe money (box selected but not fully paid).
     * Subtracts any deposits already paid to get the true outstanding amount.
     */
    private function calculateAccountsReceivable(?string $start = null, ?string $end = null): array
    {
        // Base query: orders not fully paid and not cancelled
        $query = Order::whereNull('paid_at')
            ->where('status', '!=', Order::STATUS_CANCELLED);

        // Apply date filter if provided (based on order creation date)
        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        // Get orders that have boxes (either in order_boxes table or legacy box_price)
        $ordersWithBoxes = (clone $query)
            ->where(function ($q) {
                $q->whereHas('boxes')
                    ->orWhereNotNull('box_price');
            })
            ->with('boxes')
            ->get();

        // Calculate total receivable amount (box price minus any deposits paid)
        $totalAmount = 0;
        foreach ($ordersWithBoxes as $order) {
            $boxPrice = $order->calculateTotalBoxPrice();

            // Subtract deposit if already paid
            $depositPaid = 0;
            if ($order->deposit_paid_at && $order->deposit_amount) {
                $depositPaid = (float) $order->deposit_amount;
            }

            $outstanding = $boxPrice - $depositPaid;
            if ($outstanding > 0) {
                $totalAmount += $outstanding;
            }
        }

        return [
            'total' => round($totalAmount, 2),
            'count' => $ordersWithBoxes->count(),
        ];
    }
}
