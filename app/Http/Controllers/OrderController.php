<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Requests\CompleteOrderRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Mail\OrderCreatedNoPayment;
use App\Notifications\NewOrderNotification;
use Illuminate\Support\Facades\Notification;

class OrderController extends Controller
{
    /**
     * Display a listing of the user's orders.
     */
    public function index(Request $request)
    {
        $query = Order::with(['items'])
            ->forUser($request->user()->id);
        
        // Add search functionality
        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('order_number', 'like', '%' . $searchTerm . '%')
                  ->orWhere('tracking_number', 'like', '%' . $searchTerm . '%');
            });
        }
        
        // Add status filter
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }
        
        $orders = $query->latest()->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Create a new order without payment
     * User no longer selects box size - admin will determine this when preparing quote
     */
    public function create(Request $request)
    {
        $request->validate([
            'delivery_address' => 'required|array',
            'delivery_address.street' => 'required|string|max:255',
            'delivery_address.exterior_number' => 'required|string|max:20',
            'delivery_address.interior_number' => 'nullable|string|max:20',
            'delivery_address.colonia' => 'required|string|max:100',
            'delivery_address.municipio' => 'required|string|max:100',
            'delivery_address.estado' => 'required|string|max:100',
            'delivery_address.postal_code' => 'required|regex:/^\d{5}$/',
            'delivery_address.referencias' => 'nullable|string|max:500',
            'is_rural' => 'boolean',
            'notes' => 'nullable|string|max:1000',
            'declared_value' => 'nullable|numeric|min:0|max:999999.99', // For customs/IVA calculation
        ]);

        $user = $request->user();
        
        DB::beginTransaction();
        
        try {
            // Create the order without payment or box size
            $order = Order::create([
                'user_id' => $user->id,
                'order_number' => Order::generateOrderNumber(),
                'tracking_number' => Order::generateTrackingNumber(),
                'status' => Order::STATUS_COLLECTING,
                'box_size' => null, // Will be determined by admin when preparing quote
                'is_rural' => $request->is_rural ?? false,
                'delivery_address' => $request->delivery_address,
                'currency' => 'mxn',
                // Customs/IVA related fields
                'declared_value' => $request->declared_value ?? null, // Used for IVA calculation
                'iva_amount' => null, // Will be calculated when admin prepares quote
                // Payment fields will be null until quote is paid
                'amount_paid' => null,
                'paid_at' => null,
                'stripe_checkout_session_id' => null,
                'stripe_payment_intent_id' => null,
                'stripe_invoice_id' => null,
                // Box price will be set by admin when preparing quote
                'box_price' => null,
                'notes' => $request->notes,
            ]);

            DB::commit();

            // Send confirmation email to customer
            Mail::to($user)->send(new OrderCreatedNoPayment($order));

            // Notify admins about new order
            $admins = User::where('role', 'admin')->get();
            if ($admins->count() > 0) {
                Notification::send($admins, new NewOrderNotification($order));
            }

            Log::info('Order created without payment', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'tracking_number' => $order->tracking_number,
                'user_id' => $user->id,
                'user_email' => $user->email,
                'is_rural' => $order->is_rural,
                'declared_value' => $request->declared_value,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully. You can now add items and ship them to our warehouse.',
                'data' => [
                    'order' => $order->load('items'),
                    'warehouse_address' => $this->getWarehouseAddress($order),
                    'next_steps' => $this->getNextSteps($user->preferred_language ?? 'es'),
                    'important_notes' => $this->getImportantNotes($user->preferred_language ?? 'es'),
                ]
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

    /**
     * Display the specified order.
     */
    public function show(Request $request, Order $order)
    {
        // Check if user owns this order
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $order->load('items');

        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }

    // Update the update method in OrderController:
    public function update(Request $request, Order $order)
    {
        // Check if user owns this order
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Only allow updates if order is still collecting or awaiting packages
        if (!in_array($order->status, [Order::STATUS_COLLECTING, Order::STATUS_AWAITING_PACKAGES])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update order in current status'
            ], 400);
        }

        $request->validate([
            'delivery_address' => 'sometimes|required|array',
            'delivery_address.street' => 'sometimes|required|string|max:255',
            'delivery_address.exterior_number' => 'sometimes|required|string|max:20',
            'delivery_address.interior_number' => 'nullable|string|max:20',
            'delivery_address.colonia' => 'sometimes|required|string|max:100',
            'delivery_address.municipio' => 'sometimes|required|string|max:100',
            'delivery_address.estado' => 'sometimes|required|string|max:100',
            'delivery_address.postal_code' => 'sometimes|required|regex:/^\d{5}$/',
            'delivery_address.referencias' => 'nullable|string|max:500',
            'is_rural' => 'sometimes|boolean',
            'declared_value' => 'sometimes|numeric|min:0|max:999999.99',
            'notes' => 'nullable|string|max:1000',
        ]);

        $order->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Order updated successfully',
            'data' => $order->fresh()->load('items')
        ]);
    }

    // Update the destroy method in OrderController:

    public function destroy(Request $request, Order $order)
    {
        // Check if user owns this order
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Only allow deletion if order is still collecting or awaiting packages (but no packages arrived)
        if (!in_array($order->status, [Order::STATUS_COLLECTING, Order::STATUS_AWAITING_PACKAGES])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete order that is being processed'
            ], 400);
        }

        // Don't allow deletion if any packages have arrived
        if ($order->arrivedItems()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete order with packages that have already arrived'
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

    /**
     * Mark order as complete (ready for consolidation)
     */
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
     * Reopen order for modifications (return to collecting status)
     */
    public function reopen(Request $request, Order $order)
    {
        // Check if user owns this order
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        try {
            // Use the model's method which already has the business logic
            $order->reopenForEditing();
            
            return response()->json([
                'success' => true,
                'message' => 'Order reopened for modifications. You can now add or remove products.',
                'data' => $order->fresh()->load('items')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() // This will show the specific error from the model
            ], 400);
        }
    }

    /**
     * Get orders with unpaid quotes
     */
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

    /**
     * View quote details for an order
     */
    public function viewQuote(Request $request, Order $order)
    {
        // Check if user owns this order
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Check if quote has been sent
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

    /**
     * Redirect to payment link for quote
     */
    public function payQuote(Request $request, Order $order)
    {
        // Check if user owns this order
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Check if order has a payment link
        if (!$order->payment_link) {
            return response()->json([
                'success' => false,
                'message' => 'No payment link available for this order'
            ], 404);
        }

        // Check if already paid
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

    /**
     * Get order tracking info
     */
    public function tracking(Request $request, Order $order)
    {
        // Check if user owns this order
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

    /**
     * Get warehouse address for shipping
     */
    private function getWarehouseAddress(Order $order)
    {
        return [
            'name' => $order->user->name . ' (' . $order->tracking_number . ')',
            'street' => '2220 Otay Lakes Rd.',
            'suite' => 'Suite 502 #95',
            'city' => 'Chula Vista',
            'state' => 'CA',
            'zip' => '91915',
            'country' => 'United States',
            'phone' => '+1 (619) 559-1920',
            'important_note' => 'MUST include user_id: ' . $order->user->id,
            'user_id' => $order->user->id,
        ];
    }

    /**
     * Get next steps instructions
     */
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

    /**
     * Get important notes for the user
     */
    private function getImportantNotes($locale = 'es')
    {
        if ($locale === 'es') {
            return [
                'no_box_selection' => 'No necesitas seleccionar un tamaño de caja. Nuestro equipo determinará el tamaño óptimo cuando lleguen todos tus paquetes.',
                'add_items_first' => 'Agrega los productos que planeas comprar antes de hacer tus compras en línea.',
                'declared_value_info' => 'El valor declarado es importante para el cálculo del IVA (16% para valores superiores a $50 USD).',
                'tracking_critical' => 'Es CRÍTICO incluir tu número de rastreo (' . 'tracking_number' . ') en TODOS los envíos.',
                'consolidation_benefit' => 'Consolidamos múltiples paquetes en uno solo para ahorrarte en costos de envío internacional.',
            ];
        }
        
        return [
            'no_box_selection' => 'You don\'t need to select a box size. Our team will determine the optimal size when all your packages arrive.',
            'add_items_first' => 'Add the products you plan to buy before making your online purchases.',
            'declared_value_info' => 'Declared value is important for IVA calculation (16% for values over $50 USD).',
            'tracking_critical' => 'It\'s CRITICAL to include your tracking number (' . 'tracking_number' . ') in ALL shipments.',
            'consolidation_benefit' => 'We consolidate multiple packages into one to save you on international shipping costs.',
        ];
    }
}