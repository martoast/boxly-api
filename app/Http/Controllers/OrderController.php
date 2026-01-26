<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Requests\CompleteOrderRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\UpdateOrderRequest;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::with(['items'])
            ->forUser($request->user()->id);

        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('order_number', 'like', '%' . $searchTerm . '%')
                    ->orWhere('tracking_number', 'like', '%' . $searchTerm . '%');
            });
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $orders = $query->latest()->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    public function create(Request $request)
    {
        $isShipping = $request->order_type === 'shipping' || $request->order_type === null;
        $hasFullAddress = !empty($request->input('delivery_address.full_address'));

        $request->validate([
            'order_type' => 'nullable|string|in:shipping,crossing',
            'delivery_address' => $isShipping ? 'required|array' : 'nullable|array',

            // Option 1: Simple full_address string
            'delivery_address.full_address' => 'nullable|string|max:1000',

            // Option 2: Individual fields (only required if full_address not provided AND shipping)
            'delivery_address.street' => ($isShipping && !$hasFullAddress) ? 'required|string|max:255' : 'nullable|string|max:255',
            'delivery_address.exterior_number' => ($isShipping && !$hasFullAddress) ? 'required|string|max:20' : 'nullable|string|max:20',
            'delivery_address.interior_number' => 'nullable|string|max:20',
            'delivery_address.colonia' => ($isShipping && !$hasFullAddress) ? 'required|string|max:100' : 'nullable|string|max:100',
            'delivery_address.municipio' => ($isShipping && !$hasFullAddress) ? 'required|string|max:100' : 'nullable|string|max:100',
            'delivery_address.estado' => ($isShipping && !$hasFullAddress) ? 'required|string|max:100' : 'nullable|string|max:100',
            'delivery_address.postal_code' => ($isShipping && !$hasFullAddress) ? 'required|regex:/^\d{5}$/' : 'nullable|regex:/^\d{5}$/',
            'delivery_address.referencias' => 'nullable|string|max:500',
            'is_rural' => 'boolean',
            'notes' => 'nullable|string|max:1000',
            'declared_value' => 'nullable|numeric|min:0|max:999999.99',
        ]);

        $user = $request->user();

        DB::beginTransaction();

        try {
            // Prepare order data
            $orderData = [
                'user_id' => $user->id,
                'order_number' => Order::generateOrderNumber(),
                'tracking_number' => Order::generateTrackingNumber(),
                'status' => Order::STATUS_COLLECTING,
                'order_type' => $request->order_type ?? 'shipping',
                'box_size' => null,
                'currency' => 'mxn',
                'declared_value' => $request->declared_value ?? null,
                'iva_amount' => null,
                'amount_paid' => null,
                'paid_at' => null,
                'stripe_checkout_session_id' => null,
                'stripe_payment_intent_id' => null,
                'stripe_invoice_id' => null,
                'box_price' => null,
                'notes' => $request->notes,
            ];

            // For crossing-only orders, force no delivery address and no rural surcharge
            if ($request->order_type === 'crossing') {
                $orderData['delivery_address'] = null;
                $orderData['is_rural'] = false;
            } else {
                $orderData['delivery_address'] = $request->delivery_address;
                $orderData['is_rural'] = $request->is_rural ?? false;
            }

            $order = Order::create($orderData);

            DB::commit();

            // Send conversion webhook if this is the user's first order
            $userOrderCount = Order::where('user_id', $user->id)->count();
            if ($userOrderCount === 1) {
                \App\Jobs\SendOrderPlacedWebhookJob::dispatch($user);
            }

            // Note: Email is automatically sent via OrderStatusChanged event when status is set to 'collecting'
            // The status-changed.blade.php template handles both shipping and crossing-only orders

            // Notify admins about new order (optional - uncomment when ready)
            // $admins = User::where('role', 'admin')->get();
            // if ($admins->count() > 0) {
            //     Notification::send($admins, new NewOrderNotification($order));
            // }

            Log::info('Order created without payment', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'tracking_number' => $order->tracking_number,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'order_type' => $order->order_type,
                'is_rural' => $order->is_rural,
                'declared_value' => $request->declared_value,
            ]);

            $responseData = [
                'order' => $order->load('items'),
                'next_steps' => $this->getNextSteps($user->preferred_language ?? 'es'),
                'important_notes' => $this->getImportantNotes($user->preferred_language ?? 'es'),
            ];

            // Only include warehouse address and standard message for shipping orders
            if ($order->isShipping()) {
                $responseData['warehouse_address'] = $this->getWarehouseAddress($order);
                $message = 'Order created successfully. You can now add items and ship them to our warehouse.';
            } else {
                $message = 'Crossing-only order created successfully. You can now add items to import.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $responseData
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create order', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create order. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function show(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $order->load('items');

        // Add metadata about what the user can do with this order
        $orderData = $order->toArray();
        $orderData['can_be_reopened'] = $order->canBeReopened();
        $orderData['can_add_items'] = in_array($order->status, [
            Order::STATUS_COLLECTING,
            Order::STATUS_AWAITING_PACKAGES,
            Order::STATUS_PACKAGES_COMPLETE
        ]);

        return response()->json([
            'success' => true,
            'data' => $orderData
        ]);
    }

    /**
     * UPDATED: Allow updates only in pre-processing statuses
     */
    public function update(UpdateOrderRequest $request, Order $order)
    {
        // Authorization and status validation already handled by UpdateOrderRequest
        $order->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Order updated successfully',
            'data' => $order->fresh()->load('items')
        ]);
    }

    public function destroy(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Only allow deletion before processing
        if (!in_array($order->status, [Order::STATUS_COLLECTING, Order::STATUS_AWAITING_PACKAGES])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete order that is being processed or has been completed'
            ], 400);
        }

        if ($order->arrivedItems()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete order with packages that have already arrived. Please contact support for assistance.'
            ], 400);
        }

        try {
            $orderNumber = $order->order_number;
            $order->delete();

            return response()->json([
                'success' => true,
                'message' => "Order '{$orderNumber}' has been deleted successfully"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete order'
            ], 500);
        }
    }

    public function complete(CompleteOrderRequest $request, Order $order)
    {
        try {
            $order->markAsComplete();

            return response()->json([
                'success' => true,
                'message' => 'Order marked as complete. We\'ll notify you when your packages arrive.',
                'data' => $order->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * UPDATED: Strict reopening - only allowed from awaiting_packages or packages_complete
     */
    public function reopen(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        try {
            $order->reopenForEditing();

            $arrivedCount = $order->arrivedItems()->count();
            $pendingCount = $order->pendingItems()->count();

            $message = 'Order reopened for modifications. You can now add new items or modify items that haven\'t arrived yet.';
            
            if ($arrivedCount > 0) {
                $message .= " Note: {$arrivedCount} item(s) have already arrived at our warehouse and cannot be removed or modified.";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'order' => $order->fresh()->load('items'),
                    'arrived_items_count' => $arrivedCount,
                    'pending_items_count' => $pendingCount,
                    'can_modify_arrived' => false,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function unpaidWithQuotes(Request $request)
    {
        $orders = Order::with(['items'])
            ->forUser($request->user()->id)
            ->unpaidWithQuotes()
            ->latest('quote_sent_at')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    public function viewQuote(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        if (!$order->quote_breakdown || !$order->quoted_amount) {
            return response()->json([
                'success' => false,
                'message' => 'No quote available for this order yet'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order_number' => $order->order_number,
                'tracking_number' => $order->tracking_number,
                'quote_breakdown' => $order->quote_breakdown,
                'quoted_amount' => $order->quoted_amount,
                'payment_link' => $order->payment_link,
                'quote_sent_at' => $order->quote_sent_at,
                'quote_expires_at' => $order->quote_expires_at,
                'is_expired' => $order->isQuoteExpired(),
                'status' => $order->status,
            ]
        ]);
    }

    public function payQuote(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        if (!$order->payment_link) {
            return response()->json([
                'success' => false,
                'message' => 'No payment link available for this order'
            ], 404);
        }

        if ($order->isPaid()) {
            return response()->json([
                'success' => false,
                'message' => 'This order has already been paid'
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'payment_link' => $order->payment_link,
                'expires_at' => $order->quote_expires_at,
                'is_expired' => $order->isQuoteExpired(),
            ]
        ]);
    }

    public function tracking(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $trackingInfo = [
            'order_number' => $order->order_number,
            'status' => $order->status,
            'status_label' => Order::getStatuses()[$order->status] ?? 'Unknown',
            'tracking_number' => $order->tracking_number,
            'arrival_progress' => $order->arrival_progress,
            'items_arrived' => $order->arrivedItems()->count(),
            'items_total' => $order->items()->count(),
            'total_weight' => $order->total_weight,
            'estimated_delivery_date' => $order->estimated_delivery_date?->format('Y-m-d'),
            'actual_delivery_date' => $order->actual_delivery_date?->format('Y-m-d'),
            'timeline' => [
                'created_at' => $order->created_at,
                'packages_complete_at' => $order->completed_at,
                'processing_started_at' => $order->processing_started_at,
                'quote_sent_at' => $order->quote_sent_at,
                'paid_at' => $order->paid_at,
                'shipped_at' => $order->shipped_at,
                'delivered_at' => $order->delivered_at,
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $trackingInfo
        ]);
    }

    private function getWarehouseAddress(Order $order)
    {
        return [
            'name' => $order->user->name . ' (' . $order->tracking_number . ')',
            'street' => '482 W. San Ysidro Blvd.',
            'suite' => 'Apt. 123',
            'city' => 'San Ysidro',
            'state' => 'CA',
            'zip' => '92173',
            'country' => 'United States',
            'phone' => '+1 (619) 559-1920',
            'important_note' => 'MUST include tracking number: ' . $order->tracking_number,
            'tracking_number' => $order->tracking_number,
        ];
    }

    private function getNextSteps($locale = 'es')
    {
        if ($locale === 'es') {
            return [
                'Agrega los productos que vas a comprar a tu orden',
                'Compra en tus tiendas en línea favoritas de USA',
                'Usa la dirección del almacén proporcionada como tu dirección de envío',
                'IMPORTANTE: Incluye tu número de rastreo en el nombre del destinatario',
                'Actualiza cada producto con su número de rastreo cuando lo tengas',
                'Una vez que todos tus paquetes lleguen, te enviaremos una cotización',
                'Realiza el pago y enviaremos tu paquete consolidado a México',
            ];
        }

        return [
            'Add the products you plan to buy to your order',
            'Shop from your favorite US online stores',
            'Use the provided warehouse address as your shipping address',
            'IMPORTANT: Include your tracking number in the recipient name',
            'Update each product with its tracking number when available',
            'Once all your packages arrive, we\'ll send you a quote',
            'Make the payment and we\'ll ship your consolidated package to Mexico',
        ];
    }

    private function getImportantNotes($locale = 'es')
    {
        if ($locale === 'es') {
            return [
                'no_box_selection' => 'No necesitas seleccionar un tamaño de caja. Nuestro equipo determinará el tamaño óptimo cuando lleguen todos tus paquetes.',
                'add_items_first' => 'Agrega los productos que planeas comprar antes de hacer tus compras en línea.',
                'declared_value_info' => 'El valor declarado es importante para el cálculo del IVA (16% para valores superiores a $50 USD).',
                'tracking_critical' => 'Es CRÍTICO incluir tu número de rastreo en TODOS los envíos.',
                'consolidation_benefit' => 'Consolidamos múltiples paquetes en uno solo para ahorrarte en costos de envío internacional.',
            ];
        }

        return [
            'no_box_selection' => 'You don\'t need to select a box size. Our team will determine the optimal size when all your packages arrive.',
            'add_items_first' => 'Add the products you plan to buy before making your online purchases.',
            'declared_value_info' => 'Declared value is important for IVA calculation (16% for values over $50 USD).',
            'tracking_critical' => 'It\'s CRITICAL to include your tracking number in ALL shipments.',
            'consolidation_benefit' => 'We consolidate multiple packages into one to save you on international shipping costs.',
        ];
    }
}