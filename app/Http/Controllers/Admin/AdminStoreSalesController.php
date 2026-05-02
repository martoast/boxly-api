<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequest;
use Illuminate\Http\Request;

/**
 * Read-only views over store-checkout PRs (a.k.a. "sales"). Reuses the
 * PurchaseRequest model — these rows are PRs with source=store. The
 * regular PR list endpoint stays focused on workflow; this endpoint
 * gives the sales-manager dashboard sales-shaped data.
 */
class AdminStoreSalesController extends Controller
{
    /**
     * Paginated list. Searches across customer name/email, request number,
     * AND product names inside the PR items.
     */
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'status'   => 'nullable|in:pending_review,quoted,paid,purchased,rejected,cancelled',
            'search'   => 'nullable|string|max:200',
        ]);

        $perPage = (int) $request->input('per_page', 20);

        $query = PurchaseRequest::with(['user:id,name,email', 'items'])
            ->where('source', PurchaseRequest::SOURCE_STORE);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->input('search'))) {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->where('request_number', 'like', $like)
                  ->orWhereHas('user', function ($u) use ($like) {
                      $u->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                  })
                  ->orWhereHas('items', function ($i) use ($like) {
                      $i->where('product_name', 'like', $like);
                  });
            });
        }

        $sales = $query->latest()->paginate($perPage);

        return response()->json(['success' => true, 'data' => $sales]);
    }

    /**
     * Aggregate stats across ALL store sales (not just the current page).
     * Cheap query — single SUM/COUNT per metric.
     */
    public function stats()
    {
        $base = PurchaseRequest::where('source', PurchaseRequest::SOURCE_STORE);

        $totalSales = (clone $base)->count();
        $paidSales  = (clone $base)->whereIn('status', [
            PurchaseRequest::STATUS_PAID,
            PurchaseRequest::STATUS_PURCHASED,
        ])->count();

        // Revenue — sum of total_amount on paid/purchased rows. Stored in MXN.
        $revenueMxn = (clone $base)
            ->whereIn('status', [PurchaseRequest::STATUS_PAID, PurchaseRequest::STATUS_PURCHASED])
            ->sum('total_amount');

        // Item count across paid sales (rough "units sold" approximation)
        $itemsSold = (int) PurchaseRequest::where('source', PurchaseRequest::SOURCE_STORE)
            ->whereIn('status', [PurchaseRequest::STATUS_PAID, PurchaseRequest::STATUS_PURCHASED])
            ->withCount('items')
            ->get()
            ->sum('items_count');

        return response()->json([
            'success' => true,
            'data' => [
                'total_sales'      => $totalSales,
                'paid_sales'       => $paidSales,
                'total_revenue_mxn'=> (float) $revenueMxn,
                'items_sold'       => $itemsSold,
            ],
        ]);
    }
}
