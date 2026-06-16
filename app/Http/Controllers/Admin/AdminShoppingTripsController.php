<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShoppingTrip;
use Illuminate\Http\Request;

/**
 * Admin CRUD for the in-person shopping schedule. The customer-facing
 * /in-person flow reads from the open trips this controller creates.
 */
class AdminShoppingTripsController extends Controller
{
    public function index(Request $request)
    {
        $query = ShoppingTrip::query()
            ->withCount('purchaseRequests')
            ->withCount(['bookings as confirmed_bookings_count' => fn ($q) => $q->where('status', 'confirmed')]);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($request->boolean('upcoming_only')) {
            $query->whereDate('trip_date', '>=', now()->toDateString());
        }

        $trips = $query->orderBy('trip_date', 'asc')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json(['success' => true, 'data' => $trips]);
    }

    public function show(ShoppingTrip $shoppingTrip)
    {
        $shoppingTrip->load(['bookings.user']);

        // Resolve store and category names for each confirmed booking so the
        // admin detail page can render them without extra fetches.
        $stores     = \App\Models\Store::pluck('name', 'id');
        $categories = \App\Models\Category::pluck('name', 'id');

        $bookings = $shoppingTrip->bookings
            ->where('status', \App\Models\ShoppingTripBooking::STATUS_CONFIRMED)
            ->values()
            ->map(function ($booking) use ($stores, $categories) {
                $data = $booking->toArray();

                // Resolve store names from IDs.
                $data['store_names'] = collect($booking->store_ids ?? [])
                    ->map(fn ($id) => $stores->get($id))
                    ->filter()
                    ->values()
                    ->all();

                // Per-store category breakdown: [['store_name'=>..., 'category_names'=>[...]], ...]
                $data['store_breakdown'] = collect($booking->store_ids ?? [])
                    ->map(function ($storeId) use ($booking, $stores, $categories) {
                        $catIds = ($booking->store_categories ?? [])[$storeId] ?? [];
                        return [
                            'store_id'       => $storeId,
                            'store_name'     => $stores->get($storeId, 'Unknown'),
                            'category_names' => collect($catIds)
                                ->map(fn ($cid) => $categories->get($cid))
                                ->filter()
                                ->values()
                                ->all(),
                        ];
                    })
                    ->values()
                    ->all();

                return $data;
            });

        $result = $shoppingTrip->toArray();
        $result['bookings'] = $bookings;

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'location'   => 'nullable|string|max:255',
            'trip_date'  => 'required|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time'   => 'nullable|date_format:H:i|after:start_time',
            'status'     => 'nullable|in:open,closed,completed',
            'notes'      => 'nullable|string',
        ]);

        $trip = ShoppingTrip::create($validated);

        return response()->json(['success' => true, 'data' => $trip], 201);
    }

    public function update(Request $request, ShoppingTrip $shoppingTrip)
    {
        $validated = $request->validate([
            'location'   => 'nullable|string|max:255',
            'trip_date'  => 'sometimes|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time'   => 'nullable|date_format:H:i|after:start_time',
            'status'     => 'sometimes|in:open,closed,completed',
            'notes'      => 'nullable|string',
        ]);

        $shoppingTrip->update($validated);

        return response()->json(['success' => true, 'data' => $shoppingTrip->fresh()]);
    }

    public function destroy(ShoppingTrip $shoppingTrip)
    {
        // PRs that booked this trip get shopping_trip_id nulled via the FK's
        // onDelete cascade (set null). Booked PRs aren't auto-cancelled — that
        // requires a deliberate admin action so the customer isn't surprised.
        $shoppingTrip->delete();

        return response()->json(['success' => true, 'message' => 'Trip deleted']);
    }
}
