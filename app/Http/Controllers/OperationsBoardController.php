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
        $request->validate(['start_date' => 'nullable|date', 'end_date' => 'nullable|date']);

        // Rolling window — the frontend sends the first/last working day shown.
        $start = ($request->filled('start_date')
            ? Carbon::parse($request->start_date)
            : Carbon::now())->startOfDay();
        $end = ($request->filled('end_date')
            ? Carbon::parse($request->end_date)
            : $start->copy()->addDays(6))->endOfDay();

        $orders = Order::with(['user', 'boxes', 'items'])
            ->whereIn('status', $this->activeStatuses)
            ->get();

        $days = [];          // 'YYYY-MM-DD' => [cards]  (scheduled, in this week)
        $needsShipDate = []; // packages_complete + awaiting_payment without a date
        $inProgress = [];    // collecting + awaiting_packages

        foreach ($orders as $order) {
            $card = $this->toCard($order);

            if ($order->status === Order::STATUS_AWAITING_PAYMENT && $order->planned_ship_date) {
                $date = Carbon::parse($order->planned_ship_date);
                if ($date->between($start, $end)) {
                    $days[$date->toDateString()][] = $card;
                }
                // scheduled outside this window => simply not shown here
            } else {
                // Everything else still needs a ship date, so it lives in the
                // backlog until consolidated/scheduled: awaiting_payment without
                // a date, packages_complete, awaiting_packages, and collecting.
                // (Each keeps its own badge so the warehouse can tell them apart.)
                $needsShipDate[] = $card;
            }
        }

        return response()->json([
            'success' => true,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => $days,
            'needs_ship_date' => $needsShipDate,
            'in_progress' => $inProgress,
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

        if ($order->status !== Order::STATUS_AWAITING_PAYMENT) {
            return response()->json([
                'success' => false,
                'message' => 'Only consolidated orders can be rescheduled. Consolidate the order to set its ship date.',
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
            Order::STATUS_PACKAGES_COMPLETE => 'needs_ship_date',
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
