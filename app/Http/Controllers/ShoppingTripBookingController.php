<?php

namespace App\Http\Controllers;

use App\Models\ShoppingTrip;
use App\Models\ShoppingTripBooking;
use App\Models\Store;
use App\Services\StripeAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShoppingTripBookingController extends Controller
{
    /**
     * Customer books an in-person shopping trip.
     *
     * Creates a booking in pending_payment status, opens a Stripe Checkout
     * Session on the MAIN account (boxes / shipping), and returns the
     * checkout_url. The webhook flips the booking to confirmed when paid.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'shopping_trip_id'   => 'required|integer|exists:shopping_trips,id',
            'store_ids'          => 'required|array|min:1',
            'store_ids.*'        => 'required|integer|exists:stores,id',
            'store_categories'   => 'nullable|array',
            'store_categories.*' => 'array',
            'store_categories.*.*' => 'integer|exists:categories,id',
        ]);

        $trip = ShoppingTrip::findOrFail($validated['shopping_trip_id']);

        if ($trip->status !== ShoppingTrip::STATUS_OPEN || $trip->trip_date->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'Esta fecha ya no está disponible.',
            ], 422);
        }

        // Reject immediately if someone already confirmed a booking for this trip.
        if ($trip->bookings()->where('status', ShoppingTripBooking::STATUS_CONFIRMED)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Alguien más acaba de reservar esta fecha. Por favor elige otra.',
            ], 422);
        }

        $storeCount = Store::whereIn('id', $validated['store_ids'])
            ->inPersonAvailable()
            ->count();

        if ($storeCount !== count($validated['store_ids'])) {
            return response()->json([
                'success' => false,
                'message' => 'One or more selected stores are not available for in-person shopping.',
            ], 422);
        }

        $user = $request->user();

        $storeCategories = collect($validated['store_categories'] ?? [])
            ->only(array_map('strval', $validated['store_ids']))
            ->filter(fn ($cats) => is_array($cats) && count($cats) > 0)
            ->map(fn ($cats) => array_values(array_unique(array_map('intval', $cats))))
            ->toArray();

        $perStoreFee    = (float) config('services.in_person.per_store_fee_usd', 10);
        $depositAmount  = round($perStoreFee * $storeCount, 2);

        $booking = ShoppingTripBooking::create([
            'user_id'           => $user->id,
            'shopping_trip_id'  => $trip->id,
            'store_ids'         => $validated['store_ids'],
            'store_categories'  => empty($storeCategories) ? null : $storeCategories,
            'booking_number'    => ShoppingTripBooking::generateBookingNumber(),
            'deposit_amount_usd'=> $depositAmount,
            'status'            => ShoppingTripBooking::STATUS_PENDING_PAYMENT,
        ]);

        // Stripe Checkout on the MAIN account (boxes / shipping).
        try {
            $stripe = StripeAccount::main();

            $tripDate = $trip->trip_date instanceof \Carbon\Carbon
                ? $trip->trip_date->isoFormat('D [de] MMMM')
                : (string) $trip->trip_date;

            $session = $stripe->checkout->sessions->create([
                'mode'       => 'payment',
                'customer'   => $user->stripeCustomerId(),
                'line_items' => [[
                    'quantity'   => 1,
                    'price_data' => [
                        'currency'     => 'usd',
                        'unit_amount'  => (int) round($depositAmount * 100),
                        'product_data' => [
                            'name'        => 'Boxly — Reserva de visita en persona',
                            'description' => sprintf(
                                '%d tienda(s) en Las Américas el %s · Reserva %s',
                                $storeCount,
                                $tripDate,
                                $booking->booking_number,
                            ),
                        ],
                    ],
                ]],
                'adaptive_pricing'    => ['enabled' => true],
                'metadata'            => [
                    'type'                    => 'trip_booking',
                    'shopping_trip_booking_id'=> $booking->id,
                    'booking_number'          => $booking->booking_number,
                ],
                'payment_intent_data' => [
                    'metadata' => [
                        'type'                    => 'trip_booking',
                        'shopping_trip_booking_id'=> $booking->id,
                        'booking_number'          => $booking->booking_number,
                    ],
                ],
                'success_url' => config('app.frontend_url') . '/shop/in-person/success?ref=' . urlencode($booking->booking_number),
                'cancel_url'  => config('app.frontend_url') . '/shop/in-person/review?cancelled=1',
            ]);

            $booking->update(['stripe_checkout_session_id' => $session->id]);

            Log::info('Shopping trip booking created', [
                'booking_id'    => $booking->id,
                'booking_number'=> $booking->booking_number,
                'user_id'       => $user->id,
                'trip_id'       => $trip->id,
                'stores'        => $storeCount,
                'deposit_usd'   => $depositAmount,
            ]);

            return response()->json([
                'success'      => true,
                'checkout_url' => $session->url,
                'data'         => $booking,
            ], 201);
        } catch (\Exception $e) {
            // Delete the pending booking so it doesn't litter the DB.
            $booking->delete();
            Log::error('Failed to create Stripe Checkout for trip booking', [
                'error'   => $e->getMessage(),
                'user_id' => $user->id,
                'trip_id' => $trip->id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Could not initiate payment. Please try again.',
            ], 500);
        }
    }
}
