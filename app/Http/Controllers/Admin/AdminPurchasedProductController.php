<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchasedProduct;
use Illuminate\Http\Request;

class AdminPurchasedProductController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchasedProduct::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('contact_phone', 'like', "%{$search}%")
                    ->orWhere('order_number', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%")
                    ->orWhere('items', 'like', "%{$search}%");
            });
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        // Date filtering (on the purchase date) — e.g. last 7/30/90 days.
        if ($from = $request->input('date_from')) {
            $query->whereDate('order_date', '>=', $from);
        }
        if ($to = $request->input('date_to')) {
            $query->whereDate('order_date', '<=', $to);
        }

        $records = $query->latest()->paginate((int) $request->input('per_page', 50));

        return response()->json(['success' => true, 'data' => $records]);
    }

    public function show(PurchasedProduct $purchasedProduct)
    {
        return response()->json(['success' => true, 'data' => $purchasedProduct]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_name'   => 'required|string|max:255',
            'contact_phone'   => 'nullable|string|max:50',
            'items'           => 'nullable|string',
            'order_number'    => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:255',
            'status'          => 'nullable|in:pending,delivered',
            'order_date'      => 'nullable|date',
        ]);

        $validated['user_id'] = $request->user()->id;
        $record = PurchasedProduct::create($validated);

        return response()->json(['success' => true, 'data' => $record], 201);
    }

    public function update(Request $request, PurchasedProduct $purchasedProduct)
    {
        $validated = $request->validate([
            'customer_name'   => 'sometimes|string|max:255',
            'contact_phone'   => 'nullable|string|max:50',
            'items'           => 'nullable|string',
            'order_number'    => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:255',
            'status'          => 'nullable|in:pending,delivered',
            'order_date'      => 'nullable|date',
        ]);

        $purchasedProduct->update($validated);

        return response()->json(['success' => true, 'data' => $purchasedProduct->fresh()]);
    }

    public function destroy(PurchasedProduct $purchasedProduct)
    {
        $purchasedProduct->delete();

        return response()->json(['success' => true, 'message' => 'Purchased product deleted']);
    }
}
