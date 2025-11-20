<?php

namespace App\Http\Controllers;

use App\Models\BusinessExpense;
use App\Models\MonthlyManualMetric;
use App\Models\Order;
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
     * Update manual metrics for a specific month
     */
    public function updateManualMetrics(Request $request)
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
            'is_manual_mode' => 'required|boolean',
            'total_revenue' => 'nullable|numeric|min:0',
            'total_expenses' => 'nullable|numeric|min:0',
            'total_profit' => 'nullable|numeric',
            'total_orders' => 'nullable|integer|min:0',
            'boxes_extra_small' => 'nullable|integer|min:0',
            'boxes_small' => 'nullable|integer|min:0',
            'boxes_medium' => 'nullable|integer|min:0',
            'boxes_large' => 'nullable|integer|min:0',
            'boxes_extra_large' => 'nullable|integer|min:0',
            'total_conversations' => 'required|integer|min:0',
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
            'total_orders' => Order::whereBetween('created_at', [$start, $end])->count(),
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
        
        // FIXED: Count purchased items more robustly
        // If 'purchased_at' is null, use 'updated_at' as a fallback for the date filter
        $purchasedItemsCount = PurchaseRequestItem::whereHas('purchaseRequest', function($q) use ($start, $end) {
            $q->where('status', 'purchased')
              ->where(function($query) use ($start, $end) {
                  $query->whereBetween('purchased_at', [$start, $end])
                        ->orWhere(function($sub) use ($start, $end) {
                            $sub->whereNull('purchased_at')
                                ->whereBetween('updated_at', [$start, $end]);
                        });
              });
        })->sum('quantity');
        
        // Service Fee Revenue
        $serviceFeeUSD = PurchaseRequest::whereBetween('paid_at', [$start, $end])
            ->whereIn('status', ['paid', 'purchased'])
            ->sum('processing_fee');
        $serviceFeeMXN = round($serviceFeeUSD * 18.00, 2);


        // --- SHIPPING METRICS ---
        $shippingRevenue = Order::whereBetween('paid_at', [$start, $end])->sum('amount_paid');
        
        $calculatedTotalRevenue = $shippingRevenue + $serviceFeeMXN;


        // --- CUSTOMERS & EXPENSES ---
        $newCustomers = User::where('role', 'customer')
            ->whereBetween('created_at', [$start, $end])
            ->count();

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
        $adSpend = $expensesByCategory['ads'];


        // === ALL TIME MODE ===
        if ($period === 'all') {
            $manualMetrics = MonthlyManualMetric::where('is_manual_mode', true)->get();
            $manualRevenue = $manualMetrics->sum('total_revenue');

            $allShippingRevenue = Order::whereNotNull('paid_at')->sum('amount_paid');
            $allServiceFeeUSD = PurchaseRequest::whereIn('status', ['paid', 'purchased'])->sum('processing_fee');
            $allServiceFeeMXN = $allServiceFeeUSD * 18.00;
            $allCalculatedRevenue = $allShippingRevenue + $allServiceFeeMXN;

            $totalRevenue = $manualRevenue + $allCalculatedRevenue;

            $manualOrders = $manualMetrics->sum('total_orders');
            $calculatedOrders = Order::count();
            $totalOrders = $manualOrders + $calculatedOrders;
            
            $totalConversations = MonthlyManualMetric::sum('total_conversations');
            
            $allExpensesQuery = BusinessExpense::query();
            $allTotalExpenses = $allExpensesQuery->sum('amount');
            $allAdSpend = $allExpensesQuery->where('category', 'ads')->sum('amount');

            $profit = $totalRevenue - $allTotalExpenses;
            $profitMargin = $totalRevenue > 0 ? ($profit / $totalRevenue) * 100 : 0;
            
            $allCustomers = User::where('role', 'customer')->count();
            $cac = $allCustomers > 0 ? round($allAdSpend / $allCustomers, 2) : 0;
            $roas = $allAdSpend > 0 ? round($totalRevenue / $allAdSpend, 2) : 0;
            $conversionRate = $totalConversations > 0 ? round(($totalOrders / $totalConversations) * 100, 2) : 0;
            
            $allPurchaseRequestsCount = PurchaseRequest::count();
            
            // FIXED: All time purchased items count simply checks status
            $allPurchasedItemsCount = PurchaseRequestItem::whereHas('purchaseRequest', function($q) {
                $q->where('status', 'purchased');
            })->sum('quantity');

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
                'expenses' => $expensesByCategory,
                'profit' => [
                    'amount' => round($profit, 2),
                    'margin' => round($profitMargin, 2),
                ],
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

        // ... (Rest of the function remains the same for Month/Calculated logic)
        
        // === SPECIFIC MONTH / PERIOD MODE ===
        $manualMetric = null;
        if ($period === 'month') {
            $manualMetric = MonthlyManualMetric::where('year', $year)
                ->where('month', $month)
                ->first();
        }

        $isManualMode = $manualMetric && $manualMetric->is_manual_mode;

        if ($isManualMode) {
            $profit = $manualMetric->total_revenue - $totalExpenses;
            $profitMargin = $manualMetric->total_revenue > 0 ? ($profit / $manualMetric->total_revenue) * 100 : 0;
            $cac = $newCustomers > 0 ? round($adSpend / $newCustomers, 2) : 0;
            $roas = $adSpend > 0 ? round($manualMetric->total_revenue / $adSpend, 2) : 0;
            $conversionRate = $manualMetric->total_conversations > 0 ? round(($manualMetric->total_orders / $manualMetric->total_conversations) * 100, 2) : 0;

            return [
                'source' => 'manual',
                'revenue' => [
                    'period_total' => round($manualMetric->total_revenue, 2),
                    'total_all_time' => round(Order::sum('amount_paid') + (\App\Models\PurchaseRequest::whereIn('status', ['paid', 'purchased'])->sum('processing_fee') * 18), 2),
                ],
                'expenses' => $expensesByCategory,
                'profit' => [
                    'amount' => round($profit, 2),
                    'margin' => round($profitMargin, 2),
                ],
                'metrics' => [
                    'total_orders' => $manualMetric->total_orders,
                    'new_customers' => $newCustomers,
                    'total_conversations' => $manualMetric->total_conversations,
                    'cac' => $cac,
                    'roas' => $roas,
                    'conversion_rate' => $conversionRate,
                    'ad_spend' => $adSpend,
                    'purchase_requests_count' => $purchaseRequestsCount,
                    'purchased_items_count' => $purchasedItemsCount,
                ],
                'manual_metrics' => [
                    'id' => $manualMetric->id,
                    'is_manual_mode' => true,
                    'notes' => $manualMetric->notes,
                    'last_updated' => $manualMetric->updated_at,
                ],
            ];
        }

        // === CALCULATED DATA (Default) ===
        
        $profit = $calculatedTotalRevenue - $totalExpenses;
        $profitMargin = $calculatedTotalRevenue > 0 ? ($profit / $calculatedTotalRevenue) * 100 : 0;

        $conversations = $manualMetric ? $manualMetric->total_conversations : 0;
        $ordersCount = Order::whereBetween('created_at', [$start, $end])->count();
        
        $cac = $newCustomers > 0 ? round($adSpend / $newCustomers, 2) : 0;
        $roas = $adSpend > 0 ? round($calculatedTotalRevenue / $adSpend, 2) : 0;
        $conversionRate = $conversations > 0 ? round(($ordersCount / $conversations) * 100, 2) : 0;

        // Period Breakdowns
        $todayShipping = Order::whereDate('paid_at', today())->sum('amount_paid');
        $todayFees = PurchaseRequest::whereDate('paid_at', today())->whereIn('status', ['paid', 'purchased'])->sum('processing_fee') * 18;
        $todayRevenue = $todayShipping + $todayFees;

        $weekShipping = Order::whereBetween('paid_at', [now()->startOfWeek(), now()->endOfWeek()])->sum('amount_paid');
        $weekFees = PurchaseRequest::whereBetween('paid_at', [now()->startOfWeek(), now()->endOfWeek()])->whereIn('status', ['paid', 'purchased'])->sum('processing_fee') * 18;
        $weekRevenue = $weekShipping + $weekFees;

        $monthShipping = Order::whereMonth('paid_at', now()->month)->whereYear('paid_at', now()->year)->sum('amount_paid');
        $monthFees = PurchaseRequest::whereMonth('paid_at', now()->month)->whereYear('paid_at', now()->year)->whereIn('status', ['paid', 'purchased'])->sum('processing_fee') * 18;
        $monthRevenue = $monthShipping + $monthFees;
        
        $totalRevenueAllTime = Order::sum('amount_paid') + (PurchaseRequest::whereIn('status', ['paid', 'purchased'])->sum('processing_fee') * 18);

        return [
            'source' => 'calculated',
            'revenue' => [
                'period_total' => round($calculatedTotalRevenue, 2),
                'today' => round($todayRevenue, 2),
                'this_week' => round($weekRevenue, 2),
                'this_month' => round($monthRevenue, 2),
                'total_all_time' => round($totalRevenueAllTime, 2),
                'breakdown' => [
                    'shipping' => round($shippingRevenue, 2),
                    'service_fees' => round($serviceFeeMXN, 2),
                ]
            ],
            'expenses' => $expensesByCategory,
            'profit' => [
                'amount' => round($profit, 2),
                'margin' => round($profitMargin, 2),
            ],
            'metrics' => [
                'new_customers' => $newCustomers,
                'total_conversations' => $conversations,
                'cac' => $cac,
                'roas' => $roas,
                'conversion_rate' => $conversionRate,
                'ad_spend' => $adSpend,
                'purchase_requests_count' => $purchaseRequestsCount,
                'purchased_items_count' => $purchasedItemsCount,
            ],
            'manual_metrics' => $manualMetric ? [
                'id' => $manualMetric->id,
                'is_manual_mode' => false,
                'notes' => $manualMetric->notes,
                'last_updated' => $manualMetric->updated_at,
            ] : null,
        ];
    }

    /**
     * Get box size distribution with manual metrics support
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
            
            $calculatedBoxes = [
                'extra-small' => Order::where('box_size', 'extra-small')->count(),
                'small' => Order::where('box_size', 'small')->count(),
                'medium' => Order::where('box_size', 'medium')->count(),
                'large' => Order::where('box_size', 'large')->count(),
                'extra-large' => Order::where('box_size', 'extra-large')->count(),
            ];
            
            $totalBoxes = [
                'extra-small' => $manualBoxes['extra-small'] + $calculatedBoxes['extra-small'],
                'small' => $manualBoxes['small'] + $calculatedBoxes['small'],
                'medium' => $manualBoxes['medium'] + $calculatedBoxes['medium'],
                'large' => $manualBoxes['large'] + $calculatedBoxes['large'],
                'extra-large' => $manualBoxes['extra-large'] + $calculatedBoxes['extra-large'],
            ];
            
            return [
                'source' => 'combined',
                'extra-small' => $totalBoxes['extra-small'],
                'small' => $totalBoxes['small'],
                'medium' => $totalBoxes['medium'],
                'large' => $totalBoxes['large'],
                'extra-large' => $totalBoxes['extra-large'],
                'not_selected' => Order::whereNull('box_size')->count(),
                'total' => array_sum($totalBoxes),
            ];
        }

        $manualMetric = MonthlyManualMetric::where('year', $year)
            ->where('month', $month)
            ->first();

        $isManualMode = $manualMetric && $manualMetric->is_manual_mode;

        if ($isManualMode) {
            return [
                'source' => 'manual',
                'extra-small' => $manualMetric->boxes_extra_small,
                'small' => $manualMetric->boxes_small,
                'medium' => $manualMetric->boxes_medium,
                'large' => $manualMetric->boxes_large,
                'extra-large' => $manualMetric->boxes_extra_large,
                'not_selected' => 0,
                'total' => $manualMetric->total_boxes,
            ];
        }

        return [
            'source' => 'calculated',
            'extra-small' => Order::whereBetween('created_at', [$start, $end])->where('box_size', 'extra-small')->count(),
            'small' => Order::whereBetween('created_at', [$start, $end])->where('box_size', 'small')->count(),
            'medium' => Order::whereBetween('created_at', [$start, $end])->where('box_size', 'medium')->count(),
            'large' => Order::whereBetween('created_at', [$start, $end])->where('box_size', 'large')->count(),
            'extra-large' => Order::whereBetween('created_at', [$start, $end])->where('box_size', 'extra-large')->count(),
            'not_selected' => Order::whereBetween('created_at', [$start, $end])->whereNull('box_size')->count(),
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