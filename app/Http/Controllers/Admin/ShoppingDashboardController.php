<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchasedProduct;
use App\Models\PurchaseRequest;
use App\Models\ShoppingReservation;
use App\Models\ShoppingReservationSlot;
use App\Models\ShoppingSlot;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The shopping manager's dashboard (Velonie): what needs her now, her numbers per day, and her
 * schedule — the in-person hours she opened for booking and which are taken. Counts only, no money
 * (Alex, 2026-10-08: same idea as the warehouse operator's dashboard).
 */
class ShoppingDashboardController extends Controller
{
    /** A quote unpaid this long needs a follow-up. */
    private const STALE_QUOTE_HOURS = 72;

    /** How many days of schedule to show, starting today. */
    private const SCHEDULE_DAYS = 14;

    /**
     * The shopping manager's dashboard. needs_now: to_quote (requests pending review), awaiting_payment
     * (quoted) + awaiting_payment_stale (quoted over 72 h ago), to_buy (paid, not yet purchased),
     * not_delivered (purchased products still pending), reservations_upcoming (confirmed in-person
     * visits from now on), final_invoices_to_send (completed visits without their final invoice),
     * refunds_pending. per_day [{day, received, quoted, paid, purchased, reservations}] for the window
     * (since + until, ISO; default the last 7 days), counted in warehouse days (tz, default
     * America/Los_Angeles). health: avg_hours_to_quote and quote_conversion (% of quotes sent in the
     * last 30 days that got paid). schedule: the next 14 days — per day the hours opened for in-person
     * booking, which are booked (customer first name, reservation number) and which are free.
     * Admin and shopping team.
     */
    public function show(Request $request)
    {
        $validated = $request->validate([
            'since' => 'nullable|date',
            'until' => 'nullable|date',
            'tz' => 'nullable|timezone',
        ]);
        $tz = $validated['tz'] ?? config('services.in_person.timezone', 'America/Los_Angeles');
        $until = isset($validated['until']) ? Carbon::parse($validated['until'])->utc() : now()->utc();
        $since = isset($validated['since']) ? Carbon::parse($validated['since'])->utc() : now($tz)->subDays(6)->startOfDay()->utc();

        return response()->json(['success' => true, 'data' => [
            'needs_now' => $this->needsNow(),
            'per_day' => $this->perDay($since, $until, $tz),
            'window' => ['since' => $since->toIso8601String(), 'until' => $until->toIso8601String(), 'tz' => $tz],
            'health' => $this->health(),
            'schedule' => $this->schedule($tz),
        ]]);
    }

    private function needsNow(): array
    {
        $staleBefore = now()->subHours(self::STALE_QUOTE_HOURS);

        return [
            'to_quote' => PurchaseRequest::where('status', PurchaseRequest::STATUS_PENDING_REVIEW)->count(),
            'awaiting_payment' => PurchaseRequest::where('status', PurchaseRequest::STATUS_QUOTED)->count(),
            'awaiting_payment_stale' => PurchaseRequest::where('status', PurchaseRequest::STATUS_QUOTED)
                ->where(fn ($q) => $q->where('quote_sent_at', '<', $staleBefore)
                    ->orWhere(fn ($q) => $q->whereNull('quote_sent_at')->where('updated_at', '<', $staleBefore)))
                ->count(),
            'to_buy' => PurchaseRequest::where('status', PurchaseRequest::STATUS_PAID)->count(),
            'not_delivered' => PurchasedProduct::where('status', PurchasedProduct::STATUS_PENDING)->count(),
            'reservations_upcoming' => ShoppingReservation::where('status', ShoppingReservation::CONFIRMED)
                ->where('starts_at', '>=', now()->startOfHour())->count(),
            'final_invoices_to_send' => ShoppingReservation::where('status', ShoppingReservation::COMPLETED)
                ->whereNull('final_invoice_sent_at')->count(),
            'refunds_pending' => ShoppingReservation::where('status', ShoppingReservation::SLOT_TAKEN)
                ->whereNotNull('paid_at')->whereNull('refunded_at')->whereNull('refund_waived_at')->count(),
        ];
    }

    /** Counts per warehouse day: requests received / quoted / paid / purchased, in-person visits booked. */
    private function perDay(Carbon $since, Carbon $until, string $tz): array
    {
        $days = [];
        $bump = function ($rows, string $column, string $key) use (&$days, $tz) {
            foreach ($rows as $at) {
                $d = Carbon::parse($at)->setTimezone($tz)->toDateString();
                $days[$d] ??= ['day' => $d, 'received' => 0, 'quoted' => 0, 'paid' => 0, 'purchased' => 0, 'reservations' => 0];
                $days[$d][$key]++;
            }
        };
        $in = fn ($q, string $col) => $q->where($col, '>=', $since)->where($col, '<', $until)->pluck($col);

        // A request still waiting for its in-person deposit isn't "received" yet (it isn't in her queue).
        $bump($in(PurchaseRequest::where('status', '!=', PurchaseRequest::STATUS_AWAITING_DEPOSIT), 'created_at'), 'created_at', 'received');
        $bump($in(PurchaseRequest::query(), 'quote_sent_at'), 'quote_sent_at', 'quoted');
        $bump($in(PurchaseRequest::query(), 'paid_at'), 'paid_at', 'paid');
        $bump($in(PurchaseRequest::query(), 'purchased_at'), 'purchased_at', 'purchased');
        $bump($in(ShoppingReservation::whereNotNull('paid_at'), 'paid_at'), 'paid_at', 'reservations');
        ksort($days);

        return array_values($days);
    }

    /** How fast quotes go out and how many get paid — the last 30 days. */
    private function health(): array
    {
        $quoted = PurchaseRequest::whereNotNull('quote_sent_at')->where('quote_sent_at', '>=', now()->subDays(30))
            ->get(['created_at', 'quote_sent_at', 'paid_at', 'status']);
        $hours = $quoted->map(fn ($r) => max(0, $r->created_at->diffInMinutes($r->quote_sent_at)) / 60);
        $paid = $quoted->filter(fn ($r) => $r->paid_at !== null || in_array($r->status, [PurchaseRequest::STATUS_PAID, PurchaseRequest::STATUS_PURCHASED], true));

        return [
            'quotes_sent_30d' => $quoted->count(),
            'avg_hours_to_quote' => $quoted->count() ? round($hours->avg(), 1) : null,
            'quote_conversion' => $quoted->count() ? round(100 * $paid->count() / $quoted->count()) : null,
        ];
    }

    /** The next SCHEDULE_DAYS days: hours she opened, which are booked, by whom. */
    private function schedule(string $tz): array
    {
        $start = now($tz)->startOfDay();
        $end = $start->copy()->addDays(self::SCHEDULE_DAYS);
        $slots = ShoppingSlot::where('location', ShoppingSlot::LOCATION)
            ->where('starts_at', '>=', $start->copy()->utc())->where('starts_at', '<', $end->copy()->utc())
            ->orderBy('starts_at')->get();
        $locks = ShoppingReservationSlot::whereIn('active_slot_id', $slots->pluck('id'))
            ->with('reservation.user:id,name')->get()->keyBy('active_slot_id');

        $days = [];
        for ($d = $start->copy(); $d->lt($end); $d = $d->addDay()) { // dates are immutable here
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'open' => 0, 'booked' => 0, 'hours' => []];
        }
        foreach ($slots as $slot) {
            $local = $slot->starts_at->copy()->setTimezone($tz);
            $key = $local->toDateString();
            if (! isset($days[$key])) {
                continue;
            }
            $r = $locks->get($slot->id)?->reservation;
            $days[$key]['hours'][] = [
                'time' => $local->format('H:i'),
                'booked' => (bool) $r,
                'customer' => $r ? (explode(' ', trim((string) $r->user?->name))[0] ?: null) : null,
                'reservation_number' => $r?->reservation_number,
            ];
            $days[$key][$r ? 'booked' : 'open']++;
        }

        return array_values($days);
    }
}
