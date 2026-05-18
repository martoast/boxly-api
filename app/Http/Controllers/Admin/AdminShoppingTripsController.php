<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShoppingTrip;
use Illuminate\Http\Request;

/**
 * Admin CRUD for the in-person shopping schedule. The customer-facing
 * /shop/in-person flow reads from the open trips this controller creates.
 */
class AdminShoppingTripsController extends Controller
{
    public function index(Request $request)
    {
        $query = ShoppingTrip::query()->withCount('purchaseRequests');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($request->boolean('upcoming_only')) {
            $query->whereDate('trip_date', '>=', now()->toDateString());
        }

        $trips = $query->orderBy('trip_date', 'desc')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json(['success' => true, 'data' => $trips]);
    }

    public function show(ShoppingTrip $shoppingTrip)
    {
        return response()->json([
            'success' => true,
            'data'    => $shoppingTrip->load(['purchaseRequests.user', 'purchaseRequests.items']),
        ]);
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
