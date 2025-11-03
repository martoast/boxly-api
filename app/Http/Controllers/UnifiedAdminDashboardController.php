<?php

namespace App\Http\Controllers;

use App\Models\BusinessExpense;
use App\Models\MonthlyManualMetric;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UnifiedAdminDashboardController extends Controller
{
    /**
     * Get comprehensive admin dashboard data
     * Supports different time periods: current, month, year
     * 
     * Query params:
     * - period: current (default) | month | year
     * - year: 2025 (defaults to current year)
     * - month: 1-12 (required if period=month)
     */
    public function index(Request $request)
    {
        $request->validate([
            'period' => 'nullable|in:current,month,year',
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
            'overview' => $this->getOverview($dateRanges),
            'orders' => $this->getOrdersData($dateRanges),
            'packages' => $this->getPackagesData($dateRanges),
            'financial' => $this->getFinancialData($dateRanges, $year, $month),
            'box_distribution' => $this->getBoxDistribution($dateRanges),
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
     * Update manual metrics for a specific month
     */
    public function updateManualMetrics(Request $request)
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
            'total_conversations' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);

        $metric = MonthlyManualMetric::getOrCreateForMonth(
            $validated['year'],
            $validated['month'],
            $request->user()->id
        );

        $metric->update([
            'total_conversations' => $validated['total_conversations'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Manual metrics updated successfully',
            'data' => $metric
        ]);
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
     * Get business overview metrics
     */
    private function getOverview(array $dateRanges): array
    {
        return [
            'total_customers' => User::where('role', 'customer')->count(),
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
            'total_orders' => Order::count(),
            'active_orders' => Order::whereIn('status', [
                Order::STATUS_COLLECTING,
                Order::STATUS_AWAITING_PACKAGES,
                Order::STATUS_PACKAGES_COMPLETE,
                Order::STATUS_PROCESSING,
                Order::STATUS_SHIPPED
            ])->count(),
        ];
    }

    /**
     * Get orders data breakdown
     */
    private function getOrdersData(array $dateRanges): array
    {
        return [
            'by_status' => [
                'collecting' => Order::status(Order::STATUS_COLLECTING)->count(),
                'awaiting_packages' => Order::status(Order::STATUS_AWAITING_PACKAGES)->count(),
                'packages_complete' => Order::status(Order::STATUS_PACKAGES_COMPLETE)->count(),
                'processing' => Order::status(Order::STATUS_PROCESSING)->count(),
                'shipped' => Order::status(Order::STATUS_SHIPPED)->count(),
                'delivered' => Order::status(Order::STATUS_DELIVERED)->count(),
                'awaiting_payment' => Order::status(Order::STATUS_AWAITING_PAYMENT)->count(),
                'paid' => Order::status(Order::STATUS_PAID)->count(),
                'cancelled' => Order::status(Order::STATUS_CANCELLED)->count(),
            ],
            'ready_for_action' => [
                'ready_to_process' => Order::status(Order::STATUS_PACKAGES_COMPLETE)->count(),
                'ready_for_invoice' => Order::status(Order::STATUS_DELIVERED)
                    ->whereNull('quote_sent_at')
                    ->count(),
                'awaiting_payment' => Order::status(Order::STATUS_AWAITING_PAYMENT)->count(),
                'expired_quotes' => Order::status(Order::STATUS_AWAITING_PAYMENT)
                    ->where('quote_expires_at', '<', now())
                    ->count(),
            ],
        ];
    }

    /**
     * Get packages/items data
     */
    private function getPackagesData(array $dateRanges): array
    {
        return [
            'total_items' => OrderItem::count(),
            'awaiting_arrival' => OrderItem::where('arrived', false)
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
            'missing_weight' => OrderItem::where('arrived', true)->whereNull('weight')->count(),
            'expected_today' => OrderItem::where('arrived', false)
                ->whereDate('estimated_delivery_date', today())
                ->count(),
            'expected_this_week' => OrderItem::where('arrived', false)
                ->whereDate('estimated_delivery_date', '>=', today())
                ->whereDate('estimated_delivery_date', '<=', now()->endOfWeek())
                ->count(),
            'overdue' => OrderItem::overdue()->count(),
            'arriving_soon' => OrderItem::arrivingSoon(3)->count(),
        ];
    }

    /**
     * Get comprehensive financial data
     */
    private function getFinancialData(array $dateRanges, int $year, int $month): array
    {
        $start = $dateRanges['start'];
        $end = $dateRanges['end'];
        $period = $dateRanges['period'];

        // Get revenue data
        $revenue = [
            'period_total' => round(Order::whereBetween('paid_at', [$start, $end])->sum('amount_paid'), 2),
            'today' => round(Order::whereDate('paid_at', today())->sum('amount_paid'), 2),
            'this_week' => round(Order::whereBetween('paid_at', [
                now()->startOfWeek(),
                now()->endOfWeek()
            ])->sum('amount_paid'), 2),
            'this_month' => round(Order::whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('amount_paid'), 2),
            'total_all_time' => round(Order::sum('amount_paid'), 2),
            'outstanding' => round(Order::where('status', Order::STATUS_AWAITING_PAYMENT)
                ->sum('quoted_amount'), 2),
            'average_order_value' => round(Order::whereNotNull('amount_paid')->avg('amount_paid'), 2),
        ];

        // Get expenses by category for the period
        $expensesQuery = BusinessExpense::whereBetween('expense_date', [$start, $end]);
        
        $expensesByCategory = [
            'shipping' => round($expensesQuery->clone()->where('category', 'shipping')->sum('amount'), 2),
            'ads' => round($expensesQuery->clone()->where('category', 'ads')->sum('amount'), 2),
            'software' => round($expensesQuery->clone()->where('category', 'software')->sum('amount'), 2),
            'office' => round($expensesQuery->clone()->where('category', 'office')->sum('amount'), 2),
            'po_box' => round($expensesQuery->clone()->where('category', 'po_box')->sum('amount'), 2),
            'misc' => round($expensesQuery->clone()->where('category', 'misc')->sum('amount'), 2),
        ];

        $totalExpenses = array_sum($expensesByCategory);
        $expensesByCategory['total'] = round($totalExpenses, 2);

        // Calculate profit
        $profit = $revenue['period_total'] - $totalExpenses;
        $profitMargin = $revenue['period_total'] > 0 
            ? ($profit / $revenue['period_total']) * 100 
            : 0;

        // Get manual metrics (only for month period)
        $manualMetrics = null;
        if ($period === 'month') {
            $metric = MonthlyManualMetric::where('year', $year)
                ->where('month', $month)
                ->first();
            
            if ($metric) {
                $manualMetrics = [
                    'conversations' => $metric->total_conversations,
                    'notes' => $metric->notes,
                ];
            }
        }

        // Calculate marketing metrics (only if we have data)
        $metrics = [];
        if ($period === 'month' && $manualMetrics) {
            $ordersInPeriod = Order::whereBetween('created_at', [$start, $end])->count();
            $newSignups = User::where('role', 'customer')
                ->whereBetween('created_at', [$start, $end])
                ->count();
            $adsExpense = $expensesByCategory['ads'];

            $metrics = [
                'conversion_rate' => $newSignups > 0 
                    ? round(($ordersInPeriod / $newSignups) * 100, 2) 
                    : 0,
                'cac' => $ordersInPeriod > 0 
                    ? round($adsExpense / $ordersInPeriod, 2) 
                    : 0,
                'roas' => $adsExpense > 0 
                    ? round($revenue['period_total'] / $adsExpense, 2) 
                    : 0,
            ];
        }

        return [
            'revenue' => $revenue,
            'expenses' => $expensesByCategory,
            'profit' => [
                'amount' => round($profit, 2),
                'margin' => round($profitMargin, 2),
            ],
            'metrics' => $metrics,
            'manual_metrics' => $manualMetrics,
        ];
    }

    /**
     * Get box size distribution
     */
    private function getBoxDistribution(array $dateRanges): array
    {
        return [
            'extra-small' => Order::where('box_size', 'extra-small')->count(),
            'small' => Order::where('box_size', 'small')->count(),
            'medium' => Order::where('box_size', 'medium')->count(),
            'large' => Order::where('box_size', 'large')->count(),
            'extra-large' => Order::where('box_size', 'extra-large')->count(),
            'not_selected' => Order::whereNull('box_size')->count(),
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
}