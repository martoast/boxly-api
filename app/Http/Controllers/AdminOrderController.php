<?php
namespace App\Http\Controllers;

use App\Http\Requests\AdminUpdateOrderStatusRequest;
use App\Models\Order;
use App\Models\OrderBox;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\AffiliateConversion;
use App\Mail\OrderShipped;
use App\Mail\OrderConsolidatedInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Cashier;

class AdminOrderController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:500',
            'limit' => 'nullable|integer|min:1|max:500',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
        ]);

        $perPage = $request->input('per_page') ?? $request->input('limit') ?? 20;

        $query = Order::with(['user', 'items', 'boxes']);

        if ($request->has('status')) {
            $query->status($request->status);
        }

        if ($request->has('items_expected_by')) {
            $query->whereHas('items', function ($q) use ($request) {
                $q->expectedBy($request->items_expected_by);
            });
        }

        if ($request->has('has_overdue_items') && $request->has_overdue_items) {
            $query->whereHas('items', function ($q) {
                $q->overdue();
            });
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $total = $query->count();
        // Tie-break on the primary key: without it, rows created in the same
        // second (bulk imports) can repeat or vanish between pages.
        $orders = $query->latest()->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders,
            'total' => $total,
        ]);
    }

    /**
     * Export orders as CSV
     */
    public function export(Request $request)
    {
        $query = Order::with(['user', 'items', 'boxes']);

        // If specific order IDs are provided, filter by those
        if ($request->has('order_ids')) {
            $query->whereIn('id', $request->order_ids);
        } else {
            // Otherwise apply filters
            if ($request->has('status')) {
                $query->status($request->status);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhere('tracking_number', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            if ($request->has('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }
        }

        $orders = $query->latest()->get();

        // Filter by pending payment if requested (calculated field)
        if ($request->has('pending_payment') && $request->pending_payment) {
            $orders = $orders->filter(function ($order) {
                if ($order->paid_at) return false;
                $totalPrice = $order->boxes->sum('box_price') ?: ($order->box_price ?? 0);
                if ($totalPrice == 0) return false;
                $pending = $totalPrice - ($order->amount_paid ?? 0);
                return $pending > 0;
            });
        }

        $csv = "Order Number,Tracking Number,Customer,Email,Phone,Status,Items,Total Price,Amount Paid,Created\n";
        foreach ($orders as $order) {
            $totalPrice = $order->boxes->sum('box_price') ?: ($order->box_price ?? 0);
            $csv .= sprintf(
                "\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",%d,%.2f,%.2f,\"%s\"\n",
                str_replace('"', '""', $order->order_number ?? ''),
                str_replace('"', '""', $order->tracking_number ?? ''),
                str_replace('"', '""', $order->user->name ?? ''),
                str_replace('"', '""', $order->user->email ?? ''),
                str_replace('"', '""', $order->user->phone ?? ''),
                str_replace('"', '""', $order->status ?? ''),
                $order->items->count(),
                $totalPrice,
                $order->amount_paid ?? 0,
                $order->created_at->format('Y-m-d')
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="orders-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }

    public function show(Order $order)
    {
        return response()->json([
            'success' => true,
            'data' => $order->load(['user', 'items', 'boxes', 'arrivalImages'])
        ]);
    }

    public function readyToShip(Request $request)
    {
        $perPage = $request->input('per_page') ?? 20;
        $query = Order::with(['user', 'items', 'boxes'])
            ->status(Order::STATUS_PAID)
            ->oldest('paid_at');

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
            'total' => $query->count(),
        ]);
    }

    public function readyToProcess(Request $request)
    {
        $perPage = $request->input('per_page') ?? 20;
        $query = Order::with(['user', 'items', 'boxes'])
            ->status(Order::STATUS_PACKAGES_COMPLETE)
            ->oldest('updated_at');

        return response()->json([
            'success' => true,
            'data' => $query->paginate($perPage),
            'total' => $query->count(),
        ]);
    }

    /**
     * Consolidate order: Select boxes and create invoice for 100% payment.
     * This happens when all items have arrived, BEFORE shipping.
     *
     * Request contains:
     * - boxes: array of [{stripe_price_id, quantity}] for each box type
     * - payment_method: 'stripe' (default) or 'manual_transfer'
     *
     * NO GIA files at this step - those come at shipping time.
     */
    public function consolidateOrder(Request $request, Order $order)
    {
        $request->validate([
            'boxes' => 'required|array|min:1',
            'boxes.*.stripe_price_id' => 'required|string',
            'boxes.*.quantity' => 'nullable|integer|min:1',
            'boxes.*.length' => 'nullable|numeric|min:0|max:999999.99',
            'boxes.*.width' => 'nullable|numeric|min:0|max:999999.99',
            'boxes.*.height' => 'nullable|numeric|min:0|max:999999.99',
            'boxes.*.weight' => 'nullable|numeric|min:0|max:999999.99',
            // Optional manual override of the amount charged for this box.
            // Defaults to the Stripe product's list price when omitted.
            'boxes.*.price' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|in:stripe,manual_transfer',
            // Required ship date — scheduling the order onto the Operations Board
            // is part of consolidating it. Drives the weekly board calendar.
            'planned_ship_date' => 'required|date',
        ]);

        $paymentMethod = $request->payment_method ?? 'stripe';

        // Allow consolidating a freshly-completed order, OR re-opening consolidation for an
        // order already sitting in awaiting_payment that hasn't been paid yet (e.g. boxes were
        // set up manually and the invoice / bank-transfer details were never sent).
        $canConsolidate = $order->status === Order::STATUS_PACKAGES_COMPLETE
            || ($order->status === Order::STATUS_AWAITING_PAYMENT && !$order->paid_at);

        if (!$canConsolidate) {
            return response()->json([
                'success' => false,
                'message' => 'Order must be in packages_complete or unpaid awaiting_payment status to consolidate'
            ], 400);
        }

        DB::beginTransaction();

        try {
            $user = $order->user;
            $stripe = Cashier::stripe();

            // If re-consolidating an order that already has a Stripe invoice (regenerating the
            // charge or switching to bank transfer), cancel the previous unpaid invoice so the
            // customer isn't left with two. Best-effort — never block re-consolidation on this.
            if ($order->stripe_invoice_id) {
                try {
                    $existingInvoice = $stripe->invoices->retrieve($order->stripe_invoice_id);
                    if ($existingInvoice->status === 'open') {
                        $stripe->invoices->voidInvoice($order->stripe_invoice_id);
                    } elseif ($existingInvoice->status === 'draft') {
                        $stripe->invoices->delete($order->stripe_invoice_id);
                    }
                } catch (\Exception $e) {
                    \Log::warning("Could not void previous invoice {$order->stripe_invoice_id}: {$e->getMessage()}");
                }
            }

            // 1. Fetch all box details from Stripe and calculate totals
            $boxEntries = [];
            $totalBoxPrice = 0;
            $currency = 'mxn';

            foreach ($request->boxes as $boxInput) {
                try {
                    $stripePrice = $stripe->prices->retrieve($boxInput['stripe_price_id'], [
                        'expand' => ['product']
                    ]);
                } catch (\Exception $e) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Invalid Stripe Price ID: {$boxInput['stripe_price_id']}"
                    ], 422);
                }

                $quantity = $boxInput['quantity'] ?? 1;
                // Manual per-box override (e.g. a reduced-size box) falls back
                // to the Stripe product's list price when not provided.
                $boxPrice = isset($boxInput['price'])
                    ? (float) $boxInput['price']
                    : $stripePrice->unit_amount / 100;
                $boxName = $stripePrice->product->name;
                $boxSize = $stripePrice->product->metadata->type ?? null;
                $currency = strtolower($stripePrice->currency);

                $boxEntries[] = [
                    'stripe_price_id' => $stripePrice->id,
                    'stripe_product_id' => $stripePrice->product->id,
                    'box_size' => $boxSize,
                    'box_name' => $boxName,
                    'box_price' => $boxPrice,
                    'currency' => $currency,
                    'quantity' => $quantity,
                    'length' => $boxInput['length'] ?? null,
                    'width' => $boxInput['width'] ?? null,
                    'height' => $boxInput['height'] ?? null,
                    'weight' => $boxInput['weight'] ?? null,
                ];

                $totalBoxPrice += ($boxPrice * $quantity);
            }

            $boxCount = count($boxEntries);
            $stripeInvoiceId = null;
            $paymentLink = null;

            // 2. Create Stripe Invoice OR skip for manual transfer
            if ($paymentMethod === 'stripe') {
                // Create Stripe Customer if needed
                if (!$user->stripe_id) {
                    $user->createAsStripeCustomer();
                }

                $invoiceDescription = $boxCount > 1
                    ? "Box Payment for Order {$order->order_number} - {$boxCount} boxes"
                    : "Box Payment for Order {$order->order_number}";

                $stripeInvoice = $stripe->invoices->create([
                    'customer' => $user->stripe_id,
                    'currency' => $currency,
                    'collection_method' => 'send_invoice',
                    'days_until_due' => 3,
                    'description' => $invoiceDescription,
                    'metadata' => [
                        'type' => 'box_payment',
                        'order_type' => $order->order_type ?? 'shipping',
                        'order_id' => (string) $order->id,
                        'order_number' => $order->order_number,
                        'box_count' => (string) $boxCount,
                        'total_box_price' => (string) $totalBoxPrice,
                    ],
                    'auto_advance' => false,
                ]);

                // Create line items for each box type
                foreach ($boxEntries as $entry) {
                    $lineDescription = $entry['quantity'] > 1
                        ? "{$entry['quantity']}x {$entry['box_name']} @ \${$entry['box_price']} each"
                        : "{$entry['box_name']} (\${$entry['box_price']})";

                    $lineAmount = round($entry['box_price'] * $entry['quantity'], 2);

                    $stripe->invoiceItems->create([
                        'customer' => $user->stripe_id,
                        'invoice' => $stripeInvoice->id,
                        'amount' => intval($lineAmount * 100),
                        'currency' => $currency,
                        'description' => $lineDescription,
                    ]);
                }

                $stripe->invoices->finalizeInvoice($stripeInvoice->id);
                $sentInvoice = $stripe->invoices->sendInvoice($stripeInvoice->id);

                $stripeInvoiceId = $stripeInvoice->id;
                $paymentLink = $sentInvoice->hosted_invoice_url;
            }

            // 3. Clear any existing boxes and create new OrderBox entries (no GIA yet)
            $order->boxes()->delete();
            foreach ($boxEntries as $entry) {
                OrderBox::create([
                    'order_id' => $order->id,
                    'stripe_price_id' => $entry['stripe_price_id'],
                    'stripe_product_id' => $entry['stripe_product_id'],
                    'box_size' => $entry['box_size'],
                    'box_name' => $entry['box_name'],
                    'box_price' => $entry['box_price'],
                    'currency' => $entry['currency'],
                    'quantity' => $entry['quantity'],
                    'length' => $entry['length'],
                    'width' => $entry['width'],
                    'height' => $entry['height'],
                    'weight' => $entry['weight'],
                ]);
            }

            // 4. Update Order with consolidation info
            $primaryBox = $boxEntries[0];
            $order->box_price = $totalBoxPrice;
            $order->currency = $currency;
            $order->box_size = count($boxEntries) === 1 ? $primaryBox['box_size'] : null;
            $order->stripe_price_id = count($boxEntries) === 1 ? $primaryBox['stripe_price_id'] : null;
            $order->stripe_product_id = count($boxEntries) === 1 ? $primaryBox['stripe_product_id'] : null;

            // Consolidation payment info
            $order->stripe_invoice_id = $stripeInvoiceId; // null for manual transfer
            $order->payment_link = $paymentLink; // null for manual transfer

            // Change status to awaiting payment
            $order->status = Order::STATUS_AWAITING_PAYMENT;

            // Planned ship date scheduled at consolidation — drives the Operations Board.
            $order->planned_ship_date = $request->planned_ship_date;

            $order->skipEmailNotifications = true;
            $order->save();

            DB::commit();

            // 5. Send consolidation email
            try {
                Mail::to($user)->queue(new OrderConsolidatedInvoice($order, $paymentMethod));
                Log::info('Order consolidated email queued', [
                    'order_id' => $order->id,
                    'box_count' => $boxCount,
                    'total_box_price' => $totalBoxPrice,
                    'payment_method' => $paymentMethod,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to queue consolidation email', ['error' => $e->getMessage()]);
            }

            $message = $paymentMethod === 'manual_transfer'
                ? "Order consolidated. Bank transfer details sent to customer."
                : ($boxCount > 1
                    ? "Order consolidated with {$boxCount} boxes. Invoice sent to customer."
                    : "Order consolidated. Invoice sent to customer.");

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'order' => $order->fresh()->load(['user', 'items', 'boxes']),
                    'payment_method' => $paymentMethod,
                    'payment_link' => $paymentLink,
                    'boxes' => $boxEntries,
                    'total_box_price' => $totalBoxPrice,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to consolidate order', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Manually mark consolidation as paid (for manual bank transfers).
     */
    public function markConsolidationPaid(Request $request, Order $order)
    {
        $validated = $request->validate([
            'paid_location' => 'nullable|in:' . implode(',', Order::PAID_LOCATIONS),
        ]);

        if ($order->status !== Order::STATUS_AWAITING_PAYMENT) {
            return response()->json([
                'success' => false,
                'message' => 'Order must be in awaiting_payment status'
            ], 400);
        }

        if ($order->isConsolidationPaid()) {
            return response()->json([
                'success' => false,
                'message' => 'Consolidation is already marked as paid'
            ], 400);
        }

        $order->update([
            'paid_at' => now(),
            'paid_location' => $validated['paid_location'] ?? null,
            'amount_paid' => ($order->amount_paid ?? 0) + $order->box_price,
            'status' => Order::STATUS_PAID,
        ]);

        // Refresh order to get updated values for the email
        $order->refresh();

        // Money in: credit the War Chest account it was paid to.
        \App\Models\WarChestAccount::applyDelta($order->paid_location, (float) $order->box_price, [
            'source_type' => 'order',
            'source_id' => $order->id,
            'description' => 'Orden #' . $order->order_number,
            'occurred_at' => $order->paid_at ?? now(),
            'created_by' => $request->user()->id,
        ]);

        Log::info('Consolidation manually marked as paid', [
            'order_id' => $order->id,
            'amount' => $order->box_price,
            'amount_paid' => $order->amount_paid,
            'status' => $order->status,
            'admin_id' => $request->user()->id,
        ]);

        // Queue payment confirmation email
        Mail::to($order->user)->queue(new \App\Mail\PaymentReceived($order));
        Log::info('Payment received email queued', ['order_id' => $order->id]);

        // Track affiliate conversion
        $this->trackAffiliateConversion($order);

        return response()->json([
            'success' => true,
            'message' => 'Consolidation marked as paid. Order moved to processing.',
            'data' => $order->fresh()->load(['user', 'items', 'boxes'])
        ]);
    }

    public function updateStatus(AdminUpdateOrderStatusRequest $request, Order $order)
    {
        $previousStatus = $order->status;
        $data = ['status' => $request->status];

        switch ($request->status) {
            case Order::STATUS_PROCESSING:
                $data['processing_started_at'] = now();
                break;
            case Order::STATUS_AWAITING_PAYMENT:
                if (!$order->quote_sent_at) {
                    $data['quote_sent_at'] = now();
                    $data['quote_expires_at'] = now()->addDays(7);
                }
                break;
            case Order::STATUS_PAID:
                if (!$order->paid_at) {
                    $data['paid_at'] = now();
                }
                // Set amount_paid to box_price if not already set
                if (empty($order->amount_paid) && $order->box_price) {
                    $data['amount_paid'] = $order->box_price;
                }
                // Record where it was paid, when provided
                if ($request->filled('paid_location')) {
                    $data['paid_location'] = $request->paid_location;
                }
                break;
            case Order::STATUS_SHIPPED:
                // Only set estimated_delivery_date if provided (required for shipping orders, optional for crossing)
                if ($request->has('estimated_delivery_date')) {
                    $data['estimated_delivery_date'] = $request->estimated_delivery_date;
                }
                $data['shipped_at'] = now();
                break;
            case Order::STATUS_DELIVERED:
                $data['actual_delivery_date'] = now();
                $data['delivered_at'] = now();
                break;
            case Order::STATUS_CANCELLED:
                if ($request->has('notes')) {
                    $data['notes'] = $order->notes . "\nCancelled: " . $request->notes;
                }
                break;
        }

        $order->update($data);

        Log::info('Admin manually updated order status', [
            'order_id' => $order->id,
            'previous_status' => $previousStatus,
            'new_status' => $request->status,
            'admin_id' => $request->user()->id,
        ]);

        // Send PaymentReceived email when status changes to PAID
        if ($request->status === Order::STATUS_PAID && $previousStatus !== Order::STATUS_PAID) {
            $order->refresh();
            Mail::to($order->user)->queue(new \App\Mail\PaymentReceived($order));
            Log::info('Payment received email queued via status update', ['order_id' => $order->id]);

            // Money in: credit the War Chest account it was paid to.
            \App\Models\WarChestAccount::applyDelta($order->paid_location, (float) $order->box_price, [
                'source_type' => 'order',
                'source_id' => $order->id,
                'description' => 'Orden #' . $order->order_number,
                'occurred_at' => $order->paid_at ?? now(),
                'created_by' => $request->user()->id,
            ]);

            // Track affiliate conversion
            $this->trackAffiliateConversion($order);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order status updated successfully',
            'data' => $order->fresh()->load(['user', 'items', 'boxes'])
        ]);
    }

    /**
     * Ship order: Updates existing boxes with guia numbers and GIA files.
     * Boxes are already created during consolidation step (consolidateOrder).
     * Payment is already collected at consolidation.
     *
     * Request contains:
     * - boxes: array of [{box_id, guia_number, gia_file}] for each box
     * - estimated_delivery_date: required for shipping orders
     */
    public function shipOrder(Request $request, Order $order)
    {
        // Validate request
        $request->validate([
            'boxes' => 'required|array|min:1',
            'boxes.*.box_id' => 'required|integer|exists:order_boxes,id',
            'boxes.*.guia_number' => 'nullable|string',
            'estimated_delivery_date' => $order->isCrossingOnly() ? 'nullable|date' : 'required|date',
        ]);

        if ($order->status !== Order::STATUS_PAID) {
            return response()->json([
                'success' => false,
                'message' => 'Only paid orders can be shipped'
            ], 400);
        }

        // Verify boxes belong to this order
        $boxIds = collect($request->boxes)->pluck('box_id');
        $orderBoxIds = $order->boxes->pluck('id');
        if ($boxIds->diff($orderBoxIds)->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'One or more boxes do not belong to this order'
            ], 400);
        }

        DB::beginTransaction();

        try {
            $user = $order->user;
            $userName = Str::slug($user->name);
            $isCrossing = $order->isCrossingOnly();

            // 1. Update each box with guia_number and GIA file
            foreach ($request->boxes as $boxIndex => $boxInput) {
                $box = OrderBox::find($boxInput['box_id']);

                // Update guia number
                if (isset($boxInput['guia_number'])) {
                    $box->guia_number = $boxInput['guia_number'];
                }

                // Handle GIA file upload (shipping orders only)
                $giaFile = $request->file("boxes.{$boxIndex}.gia_file");
                if ($giaFile && !$isCrossing) {
                    $storagePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}/boxes/{$boxIndex}";
                    $filename = "gia-" . time() . "-" . $boxIndex . ".pdf";

                    $uploadedPath = Storage::disk('spaces')->putFileAs($storagePath, $giaFile, $filename, 'public');
                    $url = config('filesystems.disks.spaces.url') . '/' . $uploadedPath;

                    $box->gia_path = $uploadedPath;
                    $box->gia_filename = $giaFile->getClientOriginalName();
                    $box->gia_mime_type = $giaFile->getClientMimeType();
                    $box->gia_size = $giaFile->getSize();
                    $box->gia_url = $url;
                }

                $box->save();
            }

            // 2. For backwards compatibility, store first box's GIA in order-level fields
            $firstBoxWithGia = $order->boxes()->whereNotNull('gia_path')->first();
            if ($firstBoxWithGia) {
                $order->gia_path = $firstBoxWithGia->gia_path;
                $order->gia_filename = $firstBoxWithGia->gia_filename;
                $order->gia_mime_type = $firstBoxWithGia->gia_mime_type;
                $order->gia_size = $firstBoxWithGia->gia_size;
                $order->gia_url = $firstBoxWithGia->gia_url;
            }

            // Store first box's guia for backwards compatibility
            $firstBoxWithGuia = $order->boxes()->whereNotNull('guia_number')->first();
            if ($firstBoxWithGuia) {
                $order->guia_number = $firstBoxWithGuia->guia_number;
            }

            // 3. Update order status
            // New flow: crossing orders go directly to DELIVERED (pickup complete)
            // Shipping orders go to SHIPPED (in transit)
            $order->status = $isCrossing ? Order::STATUS_DELIVERED : Order::STATUS_SHIPPED;
            $order->shipped_at = now();
            if ($request->estimated_delivery_date) {
                $order->estimated_delivery_date = $request->estimated_delivery_date;
            }
            if ($isCrossing) {
                $order->delivered_at = now();
            }

            if ($request->has('notes')) {
                $order->notes = ($order->notes ? $order->notes . "\n" : '') . "Shipped: " . $request->notes;
            }

            $order->skipEmailNotifications = true;
            $order->save();

            DB::commit();

            // 4. Send shipped email
            $boxCount = $order->boxes->count();
            try {
                Mail::to($user)->queue(new OrderShipped($order));
                Log::info('Order shipped email queued', [
                    'order_id' => $order->id,
                    'order_type' => $order->order_type ?? 'shipping',
                    'box_count' => $boxCount,
                    'is_crossing' => $isCrossing,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to queue shipped email', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'success' => true,
                'message' => $isCrossing
                    ? "Crossing order completed. Customer notified for pickup."
                    : "Order shipped successfully. Tracking info sent to customer.",
                'data' => [
                    'order' => $order->fresh()->load(['user', 'items', 'boxes']),
                    'boxes' => $order->boxes,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to ship order', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function viewGia(Request $request, Order $order)
    {
        if (!$order->gia_path) {
            return response()->json(['success' => false, 'message' => 'No GIA document'], 404);
        }
        if ($order->gia_url) {
            return redirect($order->gia_full_url);
        }
        return response()->json(['success' => false, 'message' => 'URL unavailable'], 404);
    }

    public function destroy(Request $request, Order $order)
    {
        DB::beginTransaction();
        try {
            $order->items()->each(fn($i) => $i->delete());
            if ($order->gia_path) $order->deleteGia();
            $order->delete();
            DB::commit();
            return response()->json(['success' => true, 'message' => 'Order deleted']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate(['order_ids' => 'required|array|min:1']);
        DB::beginTransaction();
        try {
            $orders = Order::whereIn('id', $request->order_ids)->get();
            foreach ($orders as $order) {
                $order->items()->each(fn($i) => $i->delete());
                if ($order->gia_path) $order->deleteGia();
                $order->delete();
            }
            DB::commit();
            return response()->json(['success' => true, 'message' => 'Orders deleted']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Upload arrival image for an order.
     * This triggers the packages_complete status and notifies the customer.
     */
    public function uploadArrivalImage(Request $request, Order $order)
    {
        $request->validate([
            'arrival_image' => 'required|image|mimes:jpeg,jpg,png,webp|max:10240', // Max 10MB
        ]);

        // Check if all items are arrived (skip this check if we're just replacing an existing image)
        if (!$order->hasArrivalImage() && !$order->allItemsArrived()) {
            return response()->json([
                'success' => false,
                'message' => 'All items must be marked as arrived before uploading the arrival image'
            ], 400);
        }

        // Determine if we should update status (only if currently in awaiting_packages)
        $shouldUpdateStatus = $order->status === Order::STATUS_AWAITING_PACKAGES;

        try {
            $user = $order->user;
            $userName = Str::slug($user->name);
            $file = $request->file('arrival_image');

            // Delete existing arrival image if present
            if ($order->hasArrivalImage()) {
                $order->deleteArrivalImage();
            }

            // Store the image
            $storagePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}";
            $extension = $file->getClientOriginalExtension();
            $filename = "arrival-image-" . time() . ".{$extension}";

            $uploadedPath = Storage::disk('spaces')->putFileAs($storagePath, $file, $filename, 'public');
            $url = config('filesystems.disks.spaces.url') . '/' . $uploadedPath;

            // Update order with image data (and status only if in awaiting_packages)
            $updateData = [
                'arrival_image_path' => $uploadedPath,
                'arrival_image_filename' => $file->getClientOriginalName(),
                'arrival_image_mime_type' => $file->getClientMimeType(),
                'arrival_image_size' => $file->getSize(),
                'arrival_image_url' => $url,
                'total_weight' => $order->calculateTotalWeight(),
            ];

            if ($shouldUpdateStatus) {
                $updateData['status'] = Order::STATUS_PACKAGES_COMPLETE;
            }

            $order->update($updateData);

            Log::info('Arrival image uploaded', [
                'order_id' => $order->id,
                'url' => $url,
                'status_updated' => $shouldUpdateStatus,
            ]);

            // Send notification email only if this is a new upload (status was updated)
            $message = 'Arrival image uploaded.';
            if ($shouldUpdateStatus) {
                try {
                    Mail::to($user)->queue(new \App\Mail\AllPackagesArrived($order));
                    Log::info('All packages arrived email queued', ['order_id' => $order->id]);
                    $message = 'Arrival image uploaded. Customer has been notified.';
                } catch (\Exception $e) {
                    Log::error('Failed to queue all packages arrived email', ['error' => $e->getMessage()]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $order->fresh()->load(['user', 'items', 'boxes'])
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to upload arrival image', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Track affiliate conversion when an order is marked as paid.
     * Creates a conversion record if the user was referred by an affiliate.
     */
    protected function trackAffiliateConversion(Order $order): void
    {
        try {
            $user = $order->user;

            // Check if user was referred by an affiliate
            $referral = $user->affiliateReferral;
            if (!$referral) {
                return;
            }

            // Check if conversion already exists for this order
            if (AffiliateConversion::where('order_id', $order->id)->exists()) {
                Log::info('Affiliate conversion already exists', ['order_id' => $order->id]);
                return;
            }

            $affiliate = $referral->affiliate;

            // Only track for active affiliates
            if (!$affiliate->isActive()) {
                Log::info('Affiliate is not active, skipping conversion', [
                    'affiliate_id' => $affiliate->id,
                    'order_id' => $order->id,
                ]);
                return;
            }

            // Calculate commission based on total box price
            $boxPrice = $order->calculateTotalBoxPrice();
            $commissionAmount = $affiliate->calculateCommission($boxPrice);

            DB::transaction(function () use ($affiliate, $referral, $order, $boxPrice, $commissionAmount) {
                // Create conversion record
                AffiliateConversion::create([
                    'affiliate_id' => $affiliate->id,
                    'referral_id' => $referral->id,
                    'order_id' => $order->id,
                    'order_amount' => $boxPrice,
                    'commission_amount' => $commissionAmount,
                    'status' => AffiliateConversion::STATUS_APPROVED,
                ]);

                // Update affiliate's total earnings
                $affiliate->increment('total_earnings', $commissionAmount);
            });

            Log::info('Affiliate conversion tracked (admin)', [
                'affiliate_id' => $affiliate->id,
                'affiliate_code' => $affiliate->affiliate_code,
                'order_id' => $order->id,
                'box_price' => $boxPrice,
                'commission_amount' => $commissionAmount,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to track affiliate conversion', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Merge multiple orders from the same user into one.
     * The order with the furthest status becomes the target.
     * All items from source orders are moved to the target, then source orders are deleted.
     */
    public function mergeOrders(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:2',
            'order_ids.*' => 'required|integer|exists:orders,id',
        ]);

        $orders = Order::whereIn('id', $request->order_ids)->with(['items', 'user'])->get();

        if ($orders->count() < 2) {
            return response()->json([
                'success' => false,
                'message' => 'At least 2 valid orders are required to merge'
            ], 400);
        }

        // Validate all orders belong to the same user
        $userIds = $orders->pluck('user_id')->unique();
        if ($userIds->count() > 1) {
            return response()->json([
                'success' => false,
                'message' => 'All orders must belong to the same user'
            ], 400);
        }

        // Status priority - furthest along wins (higher number = further along)
        $statusPriority = [
            Order::STATUS_COLLECTING => 0,
            Order::STATUS_AWAITING_PACKAGES => 1,
            Order::STATUS_PACKAGES_COMPLETE => 2,
            Order::STATUS_AWAITING_PAYMENT => 3,
            Order::STATUS_PROCESSING => 4,
            Order::STATUS_PAID => 5,
            Order::STATUS_SHIPPED => 6,
            Order::STATUS_DELIVERED => 7,
            Order::STATUS_CANCELLED => -1, // Cancelled orders have lowest priority
        ];

        // Find the target order (furthest status)
        $targetOrder = $orders->sortByDesc(function ($order) use ($statusPriority) {
            return $statusPriority[$order->status] ?? 0;
        })->first();

        $sourceOrders = $orders->filter(fn($o) => $o->id !== $targetOrder->id);

        DB::beginTransaction();
        try {
            $movedItemsCount = 0;

            // Move all items from source orders to target order
            foreach ($sourceOrders as $sourceOrder) {
                $itemsToMove = $sourceOrder->items;

                foreach ($itemsToMove as $item) {
                    $item->order_id = $targetOrder->id;
                    $item->save();
                    $movedItemsCount++;
                }

                // Delete the source order (items already moved)
                // Clean up any associated files
                if ($sourceOrder->gia_path) {
                    $sourceOrder->deleteGia();
                }
                if ($sourceOrder->hasArrivalImage()) {
                    $sourceOrder->deleteArrivalImage();
                }

                // Delete boxes associated with source order
                $sourceOrder->boxes()->delete();

                $sourceOrder->delete();
            }

            DB::commit();

            Log::info('Orders merged successfully', [
                'target_order_id' => $targetOrder->id,
                'source_order_ids' => $sourceOrders->pluck('id')->toArray(),
                'items_moved' => $movedItemsCount,
                'user_id' => $targetOrder->user_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Successfully merged " . ($sourceOrders->count() + 1) . " orders. Moved {$movedItemsCount} items.",
                'data' => $targetOrder->fresh()->load(['user', 'items', 'boxes'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to merge orders', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to merge orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Split an order: move a subset of items into a new order.
     *
     * Used for partial shipments — the items that have arrived stay on the
     * original order (which proceeds to consolidation/shipping), while the
     * items still in transit move to a new order in awaiting_packages.
     *
     * Request:
     * - item_ids: array of OrderItem IDs to MOVE OUT into the new order
     */
    public function splitOrder(Request $request, Order $order)
    {
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'required|integer|exists:order_items,id',
        ]);

        // Every item must belong to this order
        $itemsToMove = $order->items()->whereIn('id', $request->item_ids)->get();
        if ($itemsToMove->count() !== count(array_unique($request->item_ids))) {
            return response()->json([
                'success' => false,
                'message' => 'One or more items do not belong to this order',
            ], 400);
        }

        // Refuse to move every item — the original order must keep at least one
        if ($itemsToMove->count() >= $order->items()->count()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot move every item — the original order would be left empty',
            ], 400);
        }

        DB::beginTransaction();

        try {
            // New order for the items still in transit
            $newOrder = new Order([
                'user_id' => $order->user_id,
                'order_number' => Order::generateOrderNumber(),
                'tracking_number' => Order::generateTrackingNumber(),
                'status' => Order::STATUS_AWAITING_PACKAGES,
                'order_type' => $order->order_type ?? 'shipping',
                'currency' => $order->currency ?? 'mxn',
                'delivery_address' => $order->delivery_address,
                'is_rural' => $order->is_rural ?? false,
            ]);
            $newOrder->skipEmailNotifications = true;
            $newOrder->save();

            // Move the selected items onto the new order
            foreach ($itemsToMove as $item) {
                $item->order_id = $newOrder->id;
                $item->save();
            }

            DB::commit();

            Log::info('Admin split order', [
                'original_order_id' => $order->id,
                'new_order_id' => $newOrder->id,
                'items_moved' => $itemsToMove->pluck('id')->toArray(),
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Split {$itemsToMove->count()} item(s) into new order {$newOrder->order_number}. Original order {$order->order_number} keeps the rest.",
                'data' => [
                    'original_order' => $order->fresh()->load(['user', 'items', 'boxes']),
                    'new_order' => $newOrder->fresh()->load(['user', 'items', 'boxes']),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to split order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to split order: ' . $e->getMessage(),
            ], 500);
        }
    }
}