<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Weekly Operations Board — the warehouse source of truth.
 *
 * Read-only views over the existing order lifecycle (no payment logic here):
 *   - `awaiting_payment` (+ planned_ship_date)  => 🔵 Ready to Ship, on its day
 *   - everything else (no scheduled ship date)   => backlog "Needs Ship Date":
 *       · `awaiting_payment` (no date / legacy)  => 🟠 Needs Date
 *       · `packages_complete`                    => 🟠 Needs Date
 *       · `awaiting_packages` (some arrived)     => 🟢 Active Box
 *       · `awaiting_packages` (none arrived)     => 🟡 Inventory Expected
 *       · `collecting`                           => ⚪ Collecting
 *
 * The ship date is set at consolidation; this controller only reads it and
 * lets an admin re-schedule an already-consolidated order between days.
 */
class OperationsBoardController extends Controller
{
    /** How far back the (un-searched) backlog reaches, to keep old data out. */
    protected const BACKLOG_RECENCY_DAYS = 90;

    /** Statuses that still live on the board (not yet shipped/delivered/cancelled). */
    protected array $activeStatuses = [
        Order::STATUS_COLLECTING,
        Order::STATUS_AWAITING_PACKAGES,
        Order::STATUS_PACKAGES_COMPLETE,
        Order::STATUS_AWAITING_PAYMENT,
    ];

    /**
     * GET /admin/operations-board?week_start=YYYY-MM-DD
     * Returns the week's scheduled cards (by day) + the backlog + the in-progress lane.
     */
    public function index(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'since_days' => 'nullable|integer|min:0|max:3650',
            'search' => 'nullable|string|max:100',
        ]);

        // Rolling window — the frontend sends the first/last working day shown.
        $start = ($request->filled('start_date')
            ? Carbon::parse($request->start_date)
            : Carbon::now())->startOfDay();
        $end = ($request->filled('end_date')
            ? Carbon::parse($request->end_date)
            : $start->copy()->addDays(6))->endOfDay();

        // --- Weekday columns: any active order scheduled (planned_ship_date)
        // within the window, regardless of whether it's consolidated yet.
        // Bounded by the visible week, so we load them all in one shot. ---
        $scheduled = Order::with(['user', 'boxes', 'items'])
            ->whereIn('status', $this->activeStatuses)
            ->whereNotNull('planned_ship_date')
            ->whereBetween('planned_ship_date', [$start, $end])
            ->get();

        $days = []; // 'YYYY-MM-DD' => [cards]
        foreach ($scheduled as $order) {
            $days[Carbon::parse($order->planned_ship_date)->toDateString()][] = $this->toCard($order);
        }

        // --- Backlog ("Needs Ship Date"): everything still without a scheduled
        // ship date. This is the unbounded list, so it's paginated + searchable
        // so the page never has to load/compute every order at once. ---
        // Only recent orders: old/abandoned/messy data from all-time would
        // otherwise flood the backlog. A search bypasses the cutoff so a real
        // older order can still be found by name/phone/number.
        $sinceDays = (int) $request->input('since_days', self::BACKLOG_RECENCY_DAYS);
        $search = trim((string) $request->input('search', ''));

        $backlogQuery = Order::with(['user', 'boxes', 'items'])
            ->whereIn('status', $this->activeStatuses)
            ->whereNull('planned_ship_date')
            ->when($search === '' && $sinceDays > 0, function ($q) use ($sinceDays) {
                $q->where('created_at', '>=', Carbon::now()->subDays($sinceDays));
            });
        if ($search !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $backlogQuery->where(function ($q) use ($search, $digits) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('tracking_number', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($u) use ($search, $digits) {
                      $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                      if ($digits !== '') {
                          $u->orWhere('phone', 'like', "%{$digits}%");
                      }
                  });
            });
        }

        $backlog = $backlogQuery
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 30));

        return response()->json([
            'success' => true,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => $days,
            'needs_ship_date' => $backlog->getCollection()->map(fn ($o) => $this->toCard($o))->values(),
            'needs_ship_date_meta' => [
                'current_page' => $backlog->currentPage(),
                'last_page' => $backlog->lastPage(),
                'total' => $backlog->total(),
                'has_more' => $backlog->hasMorePages(),
            ],
            'in_progress' => [], // retained for response-shape compatibility
        ]);
    }

    /**
     * GET /admin/operations-board/warehouse-list
     * Tapia's one-click checklist, grouped by what to do with each box.
     */
    public function warehouseList(Request $request)
    {
        $orders = Order::with(['user', 'boxes'])
            ->whereIn('status', $this->activeStatuses)
            ->get();

        $readyToShip = [];
        $activeBoxes = [];
        $needsShipDate = [];

        foreach ($orders as $order) {
            $entry = [
                'customer_name' => $order->user?->name,
                'order_number' => $order->order_number,
                'box_summary' => $this->boxSummary($order),
                'planned_ship_date' => $this->dateString($order->planned_ship_date),
            ];

            switch ($order->status) {
                case Order::STATUS_AWAITING_PAYMENT:
                    $readyToShip[] = $entry;
                    break;
                case Order::STATUS_PACKAGES_COMPLETE:
                    $needsShipDate[] = $entry;
                    break;
                case Order::STATUS_AWAITING_PACKAGES:
                    $activeBoxes[] = $entry;
                    break;
                // `collecting` has no physical box yet — excluded from the list.
            }
        }

        return response()->json([
            'success' => true,
            'generated_at' => now()->toIso8601String(),
            'ready_to_ship' => $readyToShip,
            'active_boxes' => $activeBoxes,
            'needs_ship_date' => $needsShipDate,
        ]);
    }

    /**
     * POST /admin/orders/{order}/ship-date  { planned_ship_date: 'YYYY-MM-DD' | null }
     * Re-schedule (or un-schedule) an already-consolidated order. No re-invoice,
     * no status change, no email. Packages_complete orders must be consolidated
     * (which is where the date is first set) before they can be moved.
     */
    public function updateShipDate(Request $request, Order $order)
    {
        $request->validate([
            'planned_ship_date' => 'nullable|date',
            'notify' => 'nullable|boolean',
        ]);

        // Any active order can be scheduled onto a day (planning is decoupled from
        // consolidation). Shipped/delivered/cancelled orders are off the board.
        if (!in_array($order->status, $this->activeStatuses, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only active orders can be scheduled.',
            ], 400);
        }

        $order->skipEmailNotifications = true;
        // Admin toggle from the reschedule popup — default to notifying the customer.
        $order->skipShipDateEmail = ! $request->boolean('notify', true);
        $order->planned_ship_date = $request->planned_ship_date; // null clears it (un-schedule)
        $order->save();

        return response()->json([
            'success' => true,
            'message' => $request->planned_ship_date ? 'Ship date updated.' : 'Ship date cleared.',
            'data' => $order->fresh()->load(['user', 'boxes']),
        ]);
    }

    /** Build a board card for an order. */
    protected function toCard(Order $order): array
    {
        $itemsCount = $order->items->count();
        $arrivedCount = $order->items->where('arrived', true)->count();
        $totalBoxPrice = (float) $order->calculateTotalBoxPrice();
        $pendingPayment = !$order->paid_at
            && ($totalBoxPrice - (float) ($order->amount_paid ?? 0)) > 0;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'tracking_number' => $order->tracking_number,
            'user_id' => $order->user_id,
            'customer_name' => $order->user?->name,
            'customer_email' => $order->user?->email,
            'customer_phone' => $order->user?->phone,
            'order_type' => $order->order_type ?? 'shipping',
            'box_summary' => $this->boxSummary($order),
            'status' => $order->status,
            'badge' => $this->badge($order, $arrivedCount),
            'consolidated' => $order->status === Order::STATUS_AWAITING_PAYMENT,
            'planned_ship_date' => $this->dateString($order->planned_ship_date),
            'pending_payment' => $pendingPayment,
            'items_count' => $itemsCount,
            'arrived_count' => $arrivedCount,
        ];
    }

    /** Derive the board badge from the order's live state. */
    protected function badge(Order $order, int $arrivedCount): string
    {
        return match ($order->status) {
            Order::STATUS_AWAITING_PAYMENT => 'ready_to_ship',
            // A packages-complete box only "needs a date" until it's been
            // scheduled onto a day; once scheduled it's just a ready box.
            Order::STATUS_PACKAGES_COMPLETE => $order->planned_ship_date ? 'active_box' : 'needs_ship_date',
            Order::STATUS_AWAITING_PACKAGES => $arrivedCount > 0 ? 'active_box' : 'inventory_expected',
            default => 'collecting',
        };
    }

    protected function boxSummary(Order $order): ?string
    {
        $summary = $order->box_summary; // accessor => array like ['1x Medium Box']
        return !empty($summary) ? implode(' + ', $summary) : null;
    }

    protected function dateString($value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }
}
