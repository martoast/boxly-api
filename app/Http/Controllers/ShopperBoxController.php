<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Añadir a mi caja" — the Chrome extension's one write.
 *
 * Boxly charges a fixed rate per BOX, so a single item carries the whole
 * freight: a MX$1,118 shirt plus a MX$1,300 XS box costs MORE than the
 * MX$1,590 the shopper was being asked for at home. The panel now says that
 * out loud, which only helps if there's somewhere to put the second item.
 *
 * The rules, from COMPASS §1b:
 *
 *   ONE BOX for both pipelines. The box is an Order in `collecting`. It holds
 *   items Boxly buys AND items the shopper buys themselves — different
 *   pipelines, same shipment, because they physically travel in the same box.
 *
 *   ONE PURCHASE REQUEST PER STORE. A PR is a shopping task for the buying
 *   team ("buy these four things at Nike"), so it should be exactly one store's
 *   worth. A second Nike item appends to the open Nike PR; a Sephora item opens
 *   a Sephora PR. Both hang off the same box.
 *
 * Getting the second rule wrong has already cost us once: one chat produced
 * FIVE purchase requests and 15 item rows for 5 real products, plus five
 * confirmation emails to the customer — see the app's
 * tasks/assisted-pr-one-per-shipment.md. That failure came from creating a
 * request per interaction instead of appending to the open one, which is
 * precisely what a per-click endpoint would do by default. Hence find-or-create,
 * inside a transaction, keyed on user + store + open status.
 */
class ShopperBoxController extends Controller
{
    /**
     * Statuses where a purchase request is still being assembled.
     *
     * `pending_review` is the real enum value — not `pending`, which MySQL
     * silently truncated into an error. Deliberately excludes `quoted`: once the
     * team has priced a request, appending to it would change a total the
     * customer has already been shown.
     */
    private const OPEN_PR_STATUSES = ['pending_review'];

    /** The current box: the shopper's order that is still collecting. */
    private function openOrder($user): ?Order
    {
        return Order::where('user_id', $user->id)
            ->where('status', Order::STATUS_COLLECTING)
            ->orderByDesc('id')
            ->first();
    }

    /** GET /me/box — what's in the box right now. */
    public function show(Request $request)
    {
        $user = $request->user();
        $order = $this->openOrder($user);

        return response()->json([
            'success' => true,
            'data' => $this->state($user, $order),
        ]);
    }

    /** POST /me/box/items — put this product in the box. */
    public function addItem(Request $request)
    {
        $validated = $request->validate([
            'product_name' => 'required|string|max:255',
            'product_url' => 'required|string|max:2000',
            'store' => 'required|string|max:120',
            'price' => 'nullable|numeric|min:0',
            'quantity' => 'nullable|integer|min:1|max:20',
            'product_image_url' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:500',
            // false = the shopper buys it and ships it to their locker; we only
            // record it so the box knows what to expect. true = Boxly buys it.
            'assisted' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $assisted = (bool) ($validated['assisted'] ?? true);

        return DB::transaction(function () use ($user, $validated, $assisted) {
            $order = $this->openOrder($user);
            if (! $order) {
                $order = Order::create([
                    'user_id' => $user->id,
                    // Both are NOT NULL with no default, so omitting them made
                    // every FIRST add fail with a 500 — the shopper's very first
                    // click, the only one that has to work. It went unnoticed
                    // because the live check ran against an account that already
                    // had an open box, which skips this branch entirely.
                    //
                    // Every other place that creates an Order sets exactly this
                    // pair (AdminOrderManagementController, AdminPurchaseRequest
                    // Controller); order_type and currency are left to their DB
                    // defaults, which are already 'shipping' and 'mxn'.
                    'order_number' => Order::generateOrderNumber(),
                    'tracking_number' => Order::generateTrackingNumber(),
                    'status' => Order::STATUS_COLLECTING,
                ]);
            }

            // One PR per store, appended to — never a new one per click.
            $store = trim($validated['store']);
            $pr = PurchaseRequest::where('user_id', $user->id)
                ->whereIn('status', self::OPEN_PR_STATUSES)
                ->where('admin_notes', 'like', "%[store:{$store}]%")
                ->orderByDesc('id')
                ->first();

            if (! $pr) {
                $pr = PurchaseRequest::create([
                    'user_id' => $user->id,
                    'request_number' => $this->nextRequestNumber(),
                    'status' => 'pending_review',
                    'currency' => 'usd',
                    // The store tag is how the next item from this retailer finds
                    // its way back to this request.
                    'admin_notes' => "[store:{$store}]",
                ]);
            }

            // Same product twice = quantity, not a duplicate row. The shopper
            // clicking twice must never produce two lines for one shirt.
            $item = PurchaseRequestItem::where('purchase_request_id', $pr->id)
                ->where('product_url', $validated['product_url'])
                ->first();

            if ($item) {
                $item->increment('quantity', $validated['quantity'] ?? 1);
            } else {
                $item = PurchaseRequestItem::create([
                    'purchase_request_id' => $pr->id,
                    'product_name' => $validated['product_name'],
                    'product_url' => $validated['product_url'],
                    'product_image_url' => $validated['product_image_url'] ?? null,
                    'price' => $validated['price'] ?? null,
                    'quantity' => $validated['quantity'] ?? 1,
                    'notes' => $validated['notes'] ?? null,
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $this->state($user, $order->fresh(), $pr),
            ]);
        });
    }

    /**
     * DELETE /me/box/items/{id} — take something back out.
     *
     * Adding without removing is how someone ends up with a box they don't
     * recognise. Scoped to the caller's own open requests, so an id from another
     * customer's box is a 404 and not a deletion.
     */
    public function removeItem(Request $request, int $item)
    {
        $user = $request->user();

        $row = PurchaseRequestItem::whereHas('purchaseRequest', function ($q) use ($user) {
            $q->where('user_id', $user->id)->whereIn('status', self::OPEN_PR_STATUSES);
        })->find($item);

        if (! $row) {
            return response()->json(['success' => false, 'message' => 'Item not found.'], 404);
        }

        $pr = $row->purchaseRequest;
        $row->delete();

        // An empty request is clutter for the buying team — drop it with its
        // last item rather than leaving a phantom "buy nothing at Nike".
        if ($pr && $pr->items()->count() === 0) {
            $pr->delete();
        }

        return response()->json([
            'success' => true,
            'data' => $this->state($user, $this->openOrder($user)),
        ]);
    }

    /** PR-YYYY-XXXX, matching what the rest of the app produces. */
    private function nextRequestNumber(): string
    {
        do {
            $candidate = 'PR-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(2)));
        } while (PurchaseRequest::where('request_number', $candidate)->exists());

        return $candidate;
    }

    /**
     * Everything the panel needs to draw the box.
     *
     * Deliberately NOT a box size or a fill percentage — those come from the
     * volume model that lives in the Nuxt app (server/utils/boxMath.ts), and
     * duplicating it here would let the chat and the panel quote different box
     * sizes for the same items. This returns the contents; the caller sizes it.
     */
    private function state($user, ?Order $order, ?PurchaseRequest $current = null): array
    {
        $prs = PurchaseRequest::with('items')
            ->where('user_id', $user->id)
            ->whereIn('status', self::OPEN_PR_STATUSES)
            ->get();

        $items = [];
        foreach ($prs as $pr) {
            preg_match('/\[store:([^\]]+)\]/', (string) $pr->admin_notes, $m);
            foreach ($pr->items as $it) {
                $items[] = [
                    'id' => $it->id,
                    'name' => $it->product_name,
                    'image' => $it->product_image_url,
                    'price' => $it->price !== null ? (float) $it->price : null,
                    'quantity' => (int) $it->quantity,
                    'store' => $m[1] ?? null,
                    'url' => $it->product_url,
                ];
            }
        }

        return [
            'order_id' => $order?->id,
            'order_number' => $order?->order_number,
            'request_number' => $current?->request_number,
            'items' => $items,
            'item_count' => array_sum(array_column($items, 'quantity')),
            'stores' => array_values(array_unique(array_filter(array_column($items, 'store')))),
        ];
    }
}
