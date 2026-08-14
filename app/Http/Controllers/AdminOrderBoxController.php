<?php

namespace App\Http\Controllers;

use App\Models\OrderBox;
use Illuminate\Http\Request;

class AdminOrderBoxController extends Controller
{
    /**
     * Display a listing of all order boxes with search/filter.
     */
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:500',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $perPage = $request->input('per_page') ?? $request->input('limit') ?? 20;

        $query = OrderBox::with(['order.user']);

        // Search across multiple fields
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('guia_number', 'like', "%{$search}%")
                    ->orWhere('box_name', 'like', "%{$search}%")
                    ->orWhereHas('order', function ($q) use ($search) {
                        $q->where('order_number', 'like', "%{$search}%")
                            ->orWhere('tracking_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('order.user', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Filter by box size
        if ($request->has('box_size') && $request->box_size) {
            $query->where('box_size', $request->box_size);
        }

        // Filter by has GIA
        if ($request->has('has_gia')) {
            if ($request->boolean('has_gia')) {
                $query->where(function ($q) {
                    $q->whereNotNull('gia_path')->orWhereNotNull('gia_url');
                });
            } else {
                $query->whereNull('gia_path')->whereNull('gia_url');
            }
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // Filter by order status
        if ($request->has('order_status') && $request->order_status) {
            $query->whereHas('order', function ($q) use ($request) {
                $q->where('status', $request->order_status);
            });
        }

        // Sort options (default: latest first)
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        $allowedSorts = ['created_at', 'box_price', 'box_size', 'box_name'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $boxes = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $boxes->items(),
            'meta' => [
                'current_page' => $boxes->currentPage(),
                'last_page' => $boxes->lastPage(),
                'per_page' => $boxes->perPage(),
                'total' => $boxes->total(),
            ],
        ]);
    }

    /**
     * Display a single order box.
     */
    public function show(OrderBox $box)
    {
        $box->load(['order.user', 'order.items']);

        return response()->json([
            'success' => true,
            'data' => $box,
        ]);
    }

    /**
     * Update an order box's measured dimensions and weight.
     *
     * Used after consolidation: the OrderBox row already exists (created by
     * the consolidate step), and this records the physical measurements once
     * the box has actually been packed and weighed.
     */
    public function update(Request $request, OrderBox $box)
    {
        $request->validate([
            'length' => 'nullable|numeric|min:0|max:999999.99',
            'width'  => 'nullable|numeric|min:0|max:999999.99',
            'height' => 'nullable|numeric|min:0|max:999999.99',
            'weight' => 'nullable|numeric|min:0|max:999999.99',
            // What the courier charged for this box (MXN). Here as well as on
            // the order edit page because box close-out happens from the CLI
            // (and Telegram) as often as from the panel.
            'shipping_cost' => 'nullable|numeric|min:0|max:999999.99',
        ]);

        // only() would silently drop an absent key, which is what we want:
        // omitting shipping_cost leaves whatever was recorded before.
        $box->update($request->only(['length', 'width', 'height', 'weight', 'shipping_cost']));

        return response()->json([
            'success' => true,
            'message' => 'Box updated',
            'data' => $box->fresh(),
        ]);
    }
}
