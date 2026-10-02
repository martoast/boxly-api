<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShoppingReservation;
use App\Models\ShoppingReservationSlot;
use App\Models\ShoppingSlot;
use App\Services\InPersonReservationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The shopping team's side of the hourly reservations: publish open hours,
 * see who booked what, cancel or complete a reservation. Mounted under both
 * /shopping (shopping team) and /admin.
 */
class AdminInPersonController extends Controller
{
    public function __construct(private InPersonReservationService $service) {}

    private function range(Request $request): array
    {
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $today = $this->service->now();

        return [
            $request->input('from', $today->toDateString()),
            $request->input('to', $today->copy()->addDays(60)->toDateString()),
        ];
    }

    /**
     * The personal-shopping hours published for a date range: query ?from=YYYY-MM-DD&to=YYYY-MM-DD (default today → +60 days).
     * Each slot is one hour, {id, date, start_time "HH:MM", end_time, status "open"|"booked", reservation}. All times are
     * California (Pacific) local time; bookable hours run 09:00–18:00 (the last hour starts at 17:00).
     */
    public function slots(Request $request)
    {
        [$from, $to] = $this->range($request);
        [$start, $end] = $this->service->utcRange($from, $to);
        $slots = ShoppingSlot::where('location', ShoppingSlot::LOCATION)
            ->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->orderBy('starts_at')->get();
        $locks = ShoppingReservationSlot::whereIn('active_slot_id', $slots->pluck('id'))
            ->with('reservation.user')->get()->keyBy('active_slot_id');

        return response()->json(['success' => true, 'data' => ['slots' => $slots->map(function ($slot) use ($locks) {
            $reservation = $locks->get($slot->id)?->reservation;

            return [
                'id' => $slot->id,
                'date' => $slot->local()->toDateString(),
                'start_time' => $slot->local()->format('H:i'),
                'end_time' => $slot->local()->addHour()->format('H:i'),
                'status' => $reservation ? 'booked' : 'open',
                'reservation' => $reservation?->toApi(true),
            ];
        })->values()]]);
    }

    /** Validates a list of {date, start_time} hours on the hour, 09:00–17:00 start (US business hours, ends by 18:00). */
    private function validateHours(Request $request, string $key): array
    {
        $request->validate([
            $key => 'nullable|array',
            "$key.*.date" => 'required|date_format:Y-m-d',
            "$key.*.start_time" => ['required', 'date_format:H:i', function ($attr, $value, $fail) {
                $h = (int) substr($value, 0, 2);
                if (substr($value, 3, 2) !== '00' || $h < InPersonReservationService::FIRST_HOUR || $h > InPersonReservationService::LAST_HOUR) {
                    $fail('Las horas van de 09:00 a 18:00 (la última empieza a las 17:00), en punto.');
                }
            }],
        ]);

        return $request->input($key, []);
    }

    /**
     * Open and close personal-shopping hours. Body: {"add": [{"date": "YYYY-MM-DD", "start_time": "HH:00"}, …], "remove": [same]}
     * — either list may be omitted. One entry = one hour, California time, on the hour from 09:00 to 17:00 (ends 18:00); past
     * hours are refused. Adding an hour that is already open is harmless. An hour with a confirmed reservation cannot be
     * removed (422 with the reservation number: cancel the reservation first). Answers {added, removed}.
     */
    public function updateSlots(Request $request)
    {
        $add = $this->validateHours($request, 'add');
        $remove = $this->validateHours($request, 'remove');

        foreach ($add as $h) {
            if ($this->service->utc($h['date'], $h['start_time'])->lte(now())) {
                return response()->json(['success' => false, 'message' => 'No se pueden abrir horas pasadas'], 422);
            }
        }

        $toRemove = collect($remove)->map(fn ($h) => ShoppingSlot::where('location', ShoppingSlot::LOCATION)
            ->where('starts_at', $this->service->utc($h['date'], $h['start_time']))->first())->filter();
        $locked = ShoppingReservationSlot::whereIn('active_slot_id', $toRemove->pluck('id'))->with('reservation')->get()->keyBy('active_slot_id');
        if ($locked->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Hay horas con una reserva confirmada. Cancela la reserva primero.',
                'booked' => $toRemove->filter(fn ($s) => $locked->has($s->id))->map(fn ($s) => [
                    'date' => $s->local()->toDateString(),
                    'start_time' => $s->local()->format('H:i'),
                    'reservation_number' => $locked[$s->id]->reservation->reservation_number,
                ])->values(),
            ], 422);
        }

        $added = 0;
        $removed = 0;
        DB::transaction(function () use ($add, $toRemove, &$added, &$removed, $request) {
            foreach ($add as $h) {
                $slot = ShoppingSlot::firstOrCreate(
                    ['location' => ShoppingSlot::LOCATION, 'starts_at' => $this->service->utc($h['date'], $h['start_time'])],
                    ['created_by' => $request->user()->id],
                );
                $added += $slot->wasRecentlyCreated ? 1 : 0;
            }
            // History rows of cancelled/completed reservations would block the delete (FK restrict).
            ShoppingReservationSlot::whereIn('shopping_slot_id', $toRemove->pluck('id'))->whereNull('active_slot_id')->delete();
            $removed = ShoppingSlot::whereIn('id', $toRemove->pluck('id'))->delete();
        });

        return response()->json(['success' => true, 'data' => ['added' => $added, 'removed' => $removed]]);
    }

    /**
     * Copy every hour of one week (Monday from_week_start) onto other weeks (weeks: list of Mondays, up to 26). Past hours
     * and the source week itself are skipped; hours already open stay. Answers {copied, skipped: [{week_start, reason}]}.
     */
    public function copyWeek(Request $request)
    {
        $data = $request->validate([
            'from_week_start' => 'required|date_format:Y-m-d',
            'weeks' => 'required|array|min:1|max:26',
            'weeks.*' => 'required|date_format:Y-m-d',
        ]);
        $tz = $this->service->tz();
        foreach (array_merge([$data['from_week_start']], $data['weeks']) as $monday) {
            if (Carbon::parse($monday, 'UTC')->dayOfWeekIso !== 1) {
                return response()->json(['success' => false, 'message' => "$monday no es lunes"], 422);
            }
        }

        $weekEnd = fn (string $monday) => Carbon::parse($monday, 'UTC')->addDays(6)->toDateString();
        [$start, $end] = $this->service->utcRange($data['from_week_start'], $weekEnd($data['from_week_start']));
        $slots = ShoppingSlot::where('location', ShoppingSlot::LOCATION)
            ->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->get();
        $copied = 0;
        $skipped = [];
        foreach ($data['weeks'] as $week) {
            if ($week === $data['from_week_start']) {
                $skipped[] = ['week_start' => $week, 'reason' => 'same_week'];
            } elseif ($slots->isEmpty()) {
                $skipped[] = ['week_start' => $week, 'reason' => 'source_week_empty'];
            } elseif ($this->service->utcRange($weekEnd($week), $weekEnd($week))[1]->lte(now())) {
                $skipped[] = ['week_start' => $week, 'reason' => 'past'];
            } else {
                // Whole calendar days in UTC-parsed dates, so a DST change between the weeks cannot skew them.
                $days = (int) round(Carbon::parse($data['from_week_start'], 'UTC')->diffInDays(Carbon::parse($week, 'UTC'), false));
                foreach ($slots as $slot) {
                    $local = $slot->local();
                    $startsAt = $this->service->utc($local->copy()->addDays($days)->toDateString(), $local->format('H:i'));
                    if ($startsAt->lte(now())) {
                        continue;
                    }
                    $new = ShoppingSlot::firstOrCreate(
                        ['location' => ShoppingSlot::LOCATION, 'starts_at' => $startsAt],
                        ['created_by' => $request->user()->id],
                    );
                    $copied += $new->wasRecentlyCreated ? 1 : 0;
                }
            }
        }

        return response()->json(['success' => true, 'data' => ['copied' => $copied, 'skipped' => $skipped]]);
    }

    /**
     * Personal-shopping reservations in a date range: query ?from=YYYY-MM-DD&to=YYYY-MM-DD (default today → +60 days) and
     * optional ?status= (pending_payment, confirmed, completed, cancelled, slot_taken, expired), with the customer.
     */
    public function reservations(Request $request)
    {
        [$from, $to] = $this->range($request);
        [$start, $end] = $this->service->utcRange($from, $to);
        $query = ShoppingReservation::with('user')->where('starts_at', '>=', $start)->where('starts_at', '<', $end);
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json(['success' => true, 'data' => $query->orderBy('starts_at')->get()->map->toApi(true)->values()]);
    }

    public function pendingRefunds()
    {
        return response()->json(['success' => true, 'data' => $this->service->pendingRefunds()->map->toApi(true)->values()]);
    }

    public function markRefunded($id)
    {
        $reservation = ShoppingReservation::with('user')->findOrFail($id);
        if (! $this->service->markRefunded($reservation)) {
            return response()->json(['success' => false, 'message' => 'Esta reserva no requiere reembolso'], 422);
        }

        return response()->json(['success' => true, 'data' => $reservation->fresh('user')->toApi(true)]);
    }

    public function waiveRefund($id)
    {
        $reservation = ShoppingReservation::with('user')->findOrFail($id);
        if (! $this->service->waiveRefund($reservation)) {
            return response()->json(['success' => false, 'message' => 'Esta reserva no requiere reembolso'], 422);
        }

        return response()->json(['success' => true, 'data' => $reservation->fresh('user')->toApi(true)]);
    }

    public function reservation($id)
    {
        return response()->json(['success' => true, 'data' => ShoppingReservation::with('user')->findOrFail($id)->toApi(true)]);
    }

    public function finalInvoice($id)
    {
        $reservation = ShoppingReservation::with('user')->findOrFail($id);
        try {
            $refused = $this->service->createFinalInvoice($reservation);
        } catch (\App\Services\InPersonInvoiceSentButUnrecorded $e) {
            return response()->json(['success' => false, 'message' => 'La factura SÍ se envió al cliente en Stripe (' . $e->invoiceId . ') pero no pudimos guardarla. NO la vuelvas a generar; contacta a desarrollo.'], 502);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('In-person final invoice failed', ['reservation' => $reservation->reservation_number, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'No pudimos crear la factura en Stripe. Inténtalo de nuevo.'], 502);
        }
        if ($refused) {
            return response()->json(['success' => false, 'message' => $refused], 422);
        }

        return response()->json(['success' => true, 'data' => $reservation->fresh('user')->toApi(true)]);
    }

    public function retryFinalInvoice($id)
    {
        $reservation = ShoppingReservation::with('user')->findOrFail($id);
        try {
            $outcome = $this->service->retryFinalInvoice($reservation);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('In-person stuck final invoice check failed', ['reservation' => $reservation->reservation_number, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'no pudimos verificar Stripe. Inténtalo de nuevo en unos minutos.'], 502);
        }
        if ($outcome === null) {
            return response()->json(['success' => false, 'message' => 'Esta factura final no está atorada (debe llevar más de 10 minutos generándose sin guardarse)'], 422);
        }

        return response()->json(['success' => true, 'outcome' => $outcome, 'message' => $outcome === 'recovered' ? 'Factura recuperada: ya existía en Stripe y quedó guardada' : 'Liberada: ya puedes generar la factura final de nuevo', 'data' => $reservation->fresh('user')->toApi(true)]);
    }

    public function cancel(Request $request, $id)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $reservation = ShoppingReservation::with('user')->findOrFail($id);
        if (! $this->service->cancel($reservation, $data['reason'])) {
            return response()->json(['success' => false, 'message' => 'Solo se pueden cancelar reservas confirmadas'], 422);
        }

        return response()->json(['success' => true, 'data' => $reservation->fresh('user')->toApi(true)]);
    }

    public function complete(Request $request, $id)
    {
        $data = $request->validate(['hours_worked' => 'required|numeric|min:0|max:99', 'amount_spent_usd' => 'required|numeric|min:0|max:99999999']);
        $reservation = ShoppingReservation::with('user')->findOrFail($id);
        if (! $this->service->complete($reservation, (float) $data['hours_worked'], (float) $data['amount_spent_usd'])) {
            return response()->json(['success' => false, 'message' => 'Solo se pueden completar reservas confirmadas'], 422);
        }

        return response()->json(['success' => true, 'data' => $reservation->fresh('user')->toApi(true)]);
    }
}
