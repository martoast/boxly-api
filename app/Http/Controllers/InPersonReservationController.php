<?php

namespace App\Http\Controllers;

use App\Models\ShoppingReservation;
use App\Services\InPersonReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Customer side of the hourly in-person reservations: browse open hours,
 * reserve N consecutive hours (pay the first one through Stripe Checkout),
 * and read the reservation back on the success page.
 */
class InPersonReservationController extends Controller
{
    public function __construct(private InPersonReservationService $service) {}

    public function availability(Request $request)
    {
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $today = $this->service->now();
        $from = $request->input('from', $today->toDateString());
        $to = $request->input('to', $today->copy()->addDays(60)->toDateString());

        return response()->json(['success' => true, 'data' => $this->service->availability($from, $to)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'start_time' => 'required|date_format:H:i',
            'hours' => 'required|integer|min:1|max:' . $this->service->maxHours(),
            'customer_notes' => 'nullable|string|max:2000',
        ]);

        $startHour = (int) substr($data['start_time'], 0, 2);
        if (substr($data['start_time'], 3, 2) !== '00'
            || $startHour < InPersonReservationService::FIRST_HOUR
            || $startHour > InPersonReservationService::LAST_HOUR
            || ! $this->service->hoursAvailable($data['date'], $startHour, (int) $data['hours'])) {
            return response()->json(['success' => false, 'message' => 'Ese horario ya fue reservado'], 422);
        }

        try {
            [$reservation, $checkoutUrl] = $this->service->createReservation(
                $request->user(), $data['date'], $data['start_time'], (int) $data['hours'], $data['customer_notes'] ?? null,
            );
        } catch (\Throwable $e) {
            Log::error('Failed to create in-person reservation checkout', ['user_id' => $request->user()->id, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'No pudimos iniciar el pago. Inténtalo de nuevo.'], 502);
        }

        return response()->json(['success' => true, 'checkout_url' => $checkoutUrl, 'data' => $reservation->toApi()], 201);
    }

    public function show(Request $request, string $number)
    {
        $reservation = ShoppingReservation::where('reservation_number', $number)
            ->where('user_id', $request->user()->id)->first();
        if (! $reservation) {
            return response()->json(['success' => false, 'message' => 'Reservation not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->service->confirmIfPaid($reservation)->toApi()]);
    }
}
