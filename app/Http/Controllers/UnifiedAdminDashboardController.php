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

        // Count items from purchased requests created in this period
        $purchasedItemsCount = PurchaseRequestItem::whereHas('purchaseRequest', function ($q) use ($start, $end) {
            $q->where('status', 'purchased')
                ->whereBetween('created_at', [$start, $end]);
        })->sum('quantity');

        // Service Fee Revenue (processing_fee is our profit from assisted shopping)
        // Handle both Stripe payments (paid_at set) and manual payments (status=purchased but paid_at null)
        // Respect currency field: USD fees get converted to MXN, MXN fees stay as-is
        $serviceFeeBaseQuery = PurchaseRequest::whereIn('status', ['paid', 'purchased'])
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('paid_at', [$start, $end])
                    ->orWhereBetween('purchased_at', [$start, $end])
                    ->orWhere(function ($sub) use ($start, $end) {
                        $sub->where('status', 'purchased')
                            ->whereNull('paid_at')
                            ->whereNull('purchased_at')
                            ->whereBetween('updated_at', [$start, $end]);
                    });
            });
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
        $ordersToUse = $isOrdersManual ? $manualMetric->total_orders : Order::whereBetween('created_at', [$start, $end])->count();

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
        // Helper to get processing fees with fallback dates for manual payments
        // Respects currency: USD fees converted to MXN, MXN fees stay as-is
        $getPurchaseRequestFees = function ($dateCallback) {
            $baseQuery = PurchaseRequest::whereIn('status', ['paid', 'purchased'])
                ->where(function ($query) use ($dateCallback) {
                    $query->where(function ($q) use ($dateCallback) {
                        $dateCallback($q, 'paid_at');
                    })
                    ->orWhere(function ($q) use ($dateCallback) {
                        $dateCallback($q, 'purchased_at');
                    })
                    ->orWhere(function ($q) use ($dateCallback) {
                        $q->where('status', 'purchased')
                            ->whereNull('paid_at')
                            ->whereNull('purchased_at');
                        $dateCallback($q, 'updated_at');
                    });
                });
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
            'metrics' => [
                'total_orders' => $ordersToUse,
                'total_orders_is_manual' => $isOrdersManual,
                'total_orders_calculated' => Order::whereBetween('created_at', [$start, $end])->count(),
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
            $q->whereBetween('created_at', [$start, $end]);
        })->distinct('order_id')->pluck('order_id');

        $notSelected = Order::whereBetween('created_at', [$start, $end])
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
                $q->whereBetween('created_at', [$start, $end]);
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
            $query->whereBetween('created_at', [$start, $end]);
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
}
