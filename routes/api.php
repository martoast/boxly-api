<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderItemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AdminOrderController;
use App\Http\Controllers\AdminOrderItemController;
use App\Http\Controllers\AdminQuoteController;
use App\Http\Controllers\AdminCustomerController;
use App\Http\Controllers\AdminOrderManagementController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\Auth\AuthSocialRedirectController;
use App\Http\Controllers\Auth\AuthSocialCallbackController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\FunnelCaptureController;
use App\Http\Controllers\AdminBusinessExpenseController;
use App\Http\Controllers\UnifiedAdminDashboardController;
use App\Http\Controllers\ShipmentTrackingController;
use App\Http\Controllers\PurchaseRequestController;
use App\Http\Controllers\AdminPurchaseRequestController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);

Route::get('/', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('/products', [ProductController::class, 'index']);

Route::get('/user-types', function () {
    return response()->json([
        'success' => true,
        'data' => [
            [
                'value' => 'expat',
                'label' => 'Expat',
                'description' => 'Foreign nationals living in Mexico',
                'icon' => 'globe',
            ],
            [
                'value' => 'business',
                'label' => 'Business',
                'description' => 'Companies needing B2B solutions',
                'icon' => 'briefcase',
            ],
            [
                'value' => 'shopper',
                'label' => 'Online Shopper',
                'description' => 'Shop from US/international online stores',
                'icon' => 'shopping-cart',
            ],
        ]
    ]);
});

Route::post('/track', [TrackingController::class, 'track']);
Route::get('/track', [TrackingController::class, 'form']);

Route::prefix('shipment-tracking')->group(function () {
    Route::post('/track', [ShipmentTrackingController::class, 'track']);
    Route::get('/carriers', [ShipmentTrackingController::class, 'carriers']);
    Route::get('/carriers/search', [ShipmentTrackingController::class, 'searchCarrier']);
});

Route::post('/funnel-capture', [FunnelCaptureController::class, 'store']);

Route::middleware(['web'])->group(function () {
    Route::get('/auth/{provider}/redirect', AuthSocialRedirectController::class)
        ->whereIn('provider', ['google', 'facebook']);
    Route::get('/auth/{provider}/callback', AuthSocialCallbackController::class)
        ->whereIn('provider', ['google', 'facebook']);
});

/*
|--------------------------------------------------------------------------
| Authenticated Customer Routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        $user = $request->user();
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'user_type' => $user->user_type,
            'preferred_language' => $user->preferred_language,
            'role' => $user->role,
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
        ];
    });
    
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::get('/dashboard', [ProfileController::class, 'dashboard']);
    });

    Route::prefix('payment-methods')->group(function () {
        Route::get('/', [PaymentMethodController::class, 'index']);
        Route::post('/setup-session', [PaymentMethodController::class, 'createSetupSession']);
        Route::post('/setup-intent', [PaymentMethodController::class, 'createSetupIntent']);
        Route::post('/', [PaymentMethodController::class, 'store']);
        Route::delete('/{paymentMethodId}', [PaymentMethodController::class, 'destroy']);
        Route::put('/{paymentMethodId}/default', [PaymentMethodController::class, 'setDefault']);
    });
    
    Route::prefix('purchase-requests')->group(function () {
        Route::get('/', [PurchaseRequestController::class, 'index']);
        Route::post('/', [PurchaseRequestController::class, 'store']);
        Route::get('/{purchaseRequest}', [PurchaseRequestController::class, 'show']);
    });

    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::post('/', [OrderController::class, 'create']);
        Route::get('/unpaid', [OrderController::class, 'unpaidWithQuotes']);
        Route::get('/{order}', [OrderController::class, 'show']);
        Route::put('/{order}', [OrderController::class, 'update']);
        Route::delete('/{order}', [OrderController::class, 'destroy']);

        Route::put('/{order}/complete', [OrderController::class, 'complete']);
        Route::put('/{order}/reopen', [OrderController::class, 'reopen']);
        
        Route::get('/{order}/tracking', [OrderController::class, 'tracking']);
        Route::get('/{order}/quote', [OrderController::class, 'viewQuote']);
        Route::post('/{order}/pay-quote', [OrderController::class, 'payQuote']);
        
        Route::post('/{order}/items', [OrderItemController::class, 'store']);
        Route::put('/{order}/items/{item}', [OrderItemController::class, 'update']);
        Route::delete('/{order}/items/{item}', [OrderItemController::class, 'destroy']);
        Route::get('/{order}/items/{item}/proof', [OrderItemController::class, 'viewProof']);
    });
    
    /*
    |--------------------------------------------------------------------------
    | Authenticated Admin Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('admin')->prefix('admin')->group(function () {
        
        Route::get('/dashboard', [UnifiedAdminDashboardController::class, 'index']);
        Route::post('/dashboard/manual-metrics', [UnifiedAdminDashboardController::class, 'updateManualMetrics']);
        Route::get('/dashboard/manual-metrics', [UnifiedAdminDashboardController::class, 'getManualMetrics']);
        Route::delete('/admin/dashboard/manual-metrics', [UnifiedAdminDashboardController::class, 'deleteManualMetrics']);
        
        Route::prefix('purchase-requests')->group(function () {
            Route::get('/', [AdminPurchaseRequestController::class, 'index']);
            Route::delete('/bulk', [AdminPurchaseRequestController::class, 'bulkDestroy']);
            Route::get('/{purchaseRequest}', [AdminPurchaseRequestController::class, 'show']);
            
            Route::put('/{purchaseRequest}', [AdminPurchaseRequestController::class, 'update']);
            Route::delete('/{purchaseRequest}', [AdminPurchaseRequestController::class, 'destroy']);
            
            Route::post('/{purchaseRequest}/quote', [AdminPurchaseRequestController::class, 'createQuote']);
            Route::post('/{purchaseRequest}/mark-purchased', [AdminPurchaseRequestController::class, 'markAsPurchased']);
            Route::put('/{purchaseRequest}/reject', [AdminPurchaseRequestController::class, 'reject']);
        });

        Route::prefix('management')->group(function () {
            Route::post('/orders', [AdminOrderManagementController::class, 'createOrder']);
            Route::put('/orders/{order}', [AdminOrderManagementController::class, 'updateOrder']);
            Route::delete('/orders/{order}', [AdminOrderManagementController::class, 'deleteOrder']);
            
            Route::post('/orders/{order}/items', [AdminOrderManagementController::class, 'addItem']);
            Route::put('/orders/{order}/items/{item}', [AdminOrderManagementController::class, 'updateItem']);
            Route::delete('/orders/{order}/items/{item}', [AdminOrderManagementController::class, 'deleteItem']);
        });
        
        Route::prefix('orders')->group(function () {
            Route::get('/', [AdminOrderController::class, 'index']);
            Route::get('/ready-to-process', [AdminOrderController::class, 'readyToProcess']);
            Route::get('/ready-to-ship', [AdminOrderController::class, 'readyToShip']);
            Route::get('/ready-for-quote', [AdminQuoteController::class, 'ordersReadyForQuote']);

            Route::delete('/bulk', [AdminOrderController::class, 'bulkDestroy']);
            
            Route::get('/{order}', [AdminOrderController::class, 'show']);
            Route::put('/{order}/status', [AdminOrderController::class, 'updateStatus']);
            Route::delete('/{order}', [AdminOrderController::class, 'destroy']);
            
            Route::put('/{order}/process', [AdminQuoteController::class, 'markAsProcessing']);
            Route::post('/{order}/prepare-quote', [AdminQuoteController::class, 'prepareQuote']);
            
            Route::post('/{order}/send-invoice', [AdminQuoteController::class, 'sendInvoice']);
            Route::post('/{order}/send-quote', [AdminQuoteController::class, 'sendInvoice']);
            Route::post('/{order}/resend-invoice', [AdminQuoteController::class, 'resendInvoice']);
            Route::post('/{order}/resend-quote', [AdminQuoteController::class, 'resendInvoice']);
            Route::post('/{order}/cancel-invoice', [AdminQuoteController::class, 'cancelInvoice']);
            Route::post('/{order}/cancel-quote', [AdminQuoteController::class, 'cancelInvoice']);

            Route::post('/{order}/ship', [AdminOrderController::class, 'shipOrder']);
            Route::get('/{order}/gia', [AdminOrderController::class, 'viewGia']);
            
            Route::put('/{order}/items/{item}/arrived', [AdminOrderItemController::class, 'markArrived']);
        });
        
        Route::prefix('packages')->group(function () {
            Route::get('/', [AdminOrderItemController::class, 'index']);
            Route::get('/pending', [AdminOrderItemController::class, 'pending']);
            Route::get('/missing-weight', [AdminOrderItemController::class, 'missingWeight']);
            Route::get('/expected-today', [AdminOrderItemController::class, 'expectedToday']);
            Route::get('/overdue', [AdminOrderItemController::class, 'overdue']);
            Route::get('/arriving-soon', [AdminOrderItemController::class, 'arrivingSoon']);
            
            Route::get('/{item}', [AdminOrderItemController::class, 'show']);
            Route::put('/{item}', [AdminOrderItemController::class, 'update']);
            Route::get('/{item}/proof', [AdminOrderItemController::class, 'viewProof']);
        });
        
        Route::prefix('customers')->group(function () {
            Route::get('/', [AdminCustomerController::class, 'index']);
            Route::post('/', [AdminCustomerController::class, 'store']);
            Route::get('/{customer}', [AdminCustomerController::class, 'show']);
            Route::put('/{customer}', [AdminCustomerController::class, 'update']);
            Route::get('/{customer}/orders', [AdminCustomerController::class, 'orders']);
            Route::get('/{customer}/collecting-orders', [AdminCustomerController::class, 'collectingOrders']);
        });

        Route::prefix('expenses')->group(function () {
            Route::get('/', [AdminBusinessExpenseController::class, 'index']);
            Route::post('/', [AdminBusinessExpenseController::class, 'store']);
            Route::get('/categories', [AdminBusinessExpenseController::class, 'categories']);
            Route::post('/bulk-import', [AdminBusinessExpenseController::class, 'bulkImport']);
            Route::get('/{expense}', [AdminBusinessExpenseController::class, 'show']);
            Route::put('/{expense}', [AdminBusinessExpenseController::class, 'update']);
            Route::delete('/{expense}', [AdminBusinessExpenseController::class, 'destroy']);
        });
    });
});

Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'Endpoint not found'
    ], 404);
});