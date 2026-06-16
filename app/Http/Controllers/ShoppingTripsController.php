<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ShoppingTrip;
use App\Models\Store;
use Illuminate\Http\Request;

/**
 * Customer-facing reads that feed the in-person shopping flow at /in-person.
 * Admin CRUD for trips lives in AdminShoppingTripsController.
 */
class ShoppingTripsController extends Controller
{
    /**
     * Open trips the customer can book onto. Calendar grid reads from this.
     * 60-day window keeps the response small; admin can open further out
     * but the picker won't surface them yet.
     */
    public function availability(Request $request)
    {
        $trips = ShoppingTrip::open()
            ->whereDate('trip_date', '<=', now()->addDays(60)->toDateString())
            ->get(['id', 'location', 'trip_date', 'start_time', 'end_time', 'notes']);

        return response()->json([
            'success' => true,
            'data'    => $trips,
        ]);
    }

    /**
     * Active stores flagged available for in-person shopping. Returned with
     * the per-store fee baked in so the frontend stays dumb and the price
     * shown to the customer matches what createQuote will eventually charge.
     */
    public function inPersonStores(Request $request)
    {
        $stores = Store::active()
            ->inPersonAvailable()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'logo_url', 'description']);

        return response()->json([
            'success' => true,
            'data'    => [
                'stores'             => $stores,
                'per_store_fee_usd'  => (float) config('services.in_person.per_store_fee_usd', 10),
                'service_fee_percent'=> (float) config('services.commission.default_percent', 8),
            ],
        ]);
    }

    /**
     * Active product categories for the per-store interest picker in the
     * in-person flow.
     */
    public function categories(Request $request)
    {
        $categories = Category::active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'image_url']);

        return response()->json([
            'success' => true,
            'data'    => $categories,
        ]);
    }
}
