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
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\Auth\AuthSocialRedirectController;
use App\Http\Controllers\Auth\AuthSocialCallbackController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\FunnelCaptureController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Stripe webhooks (no auth needed)
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);

// Public routes
Route::get('/', function () {
    return response()->json(['status' => 'ok']);
});

// Products endpoint (public for pricing page)
Route::get('/products', [ProductController::class, 'index']);

// User types endpoint (public for registration)
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

// Public tracking endpoints
Route::post('/track', [TrackingController::class, 'track']);
Route::get('/track', [TrackingController::class, 'form']);

Route::post('/funnel-capture', [FunnelCaptureController::class, 'store']);

Route::middleware(['web'])->group(function () {
    Route::get('/auth/{provider}/redirect', AuthSocialRedirectController::class)
        ->whereIn('provider', ['google', 'facebook']);
    Route::get('/auth/{provider}/callback', AuthSocialCallbackController::class)
        ->whereIn('provider', ['google', 'facebook']);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // User info
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
    
    // Profile routes
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::get('/dashboard', [ProfileController::class, 'dashboard']);
    });

    // Payment Methods
    Route::prefix('payment-methods')->group(function () {
        Route::get('/', [PaymentMethodController::class, 'index']);
        Route::post('/setup-session', [PaymentMethodController::class, 'createSetupSession']);
        Route::post('/setup-intent', [PaymentMethodController::class, 'createSetupIntent']);
        Route::post('/', [PaymentMethodController::class, 'store']);
        Route::delete('/{paymentMethodId}', [PaymentMethodController::class, 'destroy']);
        Route::put('/{paymentMethodId}/default', [PaymentMethodController::class, 'setDefault']);
    });
    
    // Orders - All consolidated in OrderController
    Route::prefix('orders')->group(function () {
        // Order Management
        Route::get('/', [OrderController::class, 'index']);                           // List all user's orders
        Route::post('/', [OrderController::class, 'create']);                          // Create new order (RESTful)
        Route::get('/unpaid', [OrderController::class, 'unpaidWithQuotes']);          // Orders with pending quotes
        Route::get('/{order}', [OrderController::class, 'show']);                      // View specific order
        Route::put('/{order}', [OrderController::class, 'update']);                    // Update order (address, etc.)
        Route::delete('/{order}', [OrderController::class, 'destroy']);                // Delete order

        Route::put('/{order}/complete', [OrderController::class, 'complete']);          // Complete order ready for shipment
        Route::put('/{order}/reopen', [OrderController::class, 'reopen']);              // Reopen to add/remove items
        
        // Order Status & Tracking
        Route::get('/{order}/tracking', [OrderController::class, 'tracking']);         // Get tracking info
        Route::get('/{order}/quote', [OrderController::class, 'viewQuote']);          // View quote details
        Route::post('/{order}/pay-quote', [OrderController::class, 'payQuote']);      // Get payment link
        
        // Order Items Management
        Route::post('/{order}/items', [OrderItemController::class, 'store']);          // Add single item to order
        Route::put('/{order}/items/{item}', [OrderItemController::class, 'update']);   // Update specific item
        Route::delete('/{order}/items/{item}', [OrderItemController::class, 'destroy']); // Remove item
        Route::get('/{order}/items/{item}/proof', [OrderItemController::class, 'viewProof']); // View proof of purchase

        
    });
    
    // Admin routes
    Route::middleware('admin')->prefix('admin')->group(function () {
        
        // Dashboard
        Route::get('/dashboard', [AdminOrderController::class, 'dashboard']);
        
        // Admin Order Management
        Route::prefix('orders')->group(function () {
            // Order Listing & Filtering
            Route::get('/', [AdminOrderController::class, 'index']);                              // All orders
            Route::get('/ready-to-process', [AdminOrderController::class, 'readyToProcess']);     // Packages arrived, need quote
            Route::get('/awaiting-payment', [AdminOrderController::class, 'awaitingPayment']);    // Quotes sent, awaiting payment
            Route::get('/ready-for-quote', [AdminQuoteController::class, 'ordersReadyForQuote']); // Orders ready for quotes
            
            // Order Details & Status
            Route::get('/{order}', [AdminOrderController::class, 'show']);                        // View order details
            Route::put('/{order}/status', [AdminOrderController::class, 'updateStatus']);         // Manual status update
            
            // Quote Management
            Route::put('/{order}/process', [AdminQuoteController::class, 'markAsProcessing']);    // Mark as processing
            Route::post('/{order}/prepare-quote', [AdminQuoteController::class, 'prepareQuote']); // Prepare quote with flexibility
            Route::post('/{order}/send-quote', [AdminQuoteController::class, 'sendQuote']);       // Send quote to customer
            Route::post('/{order}/resend-quote', [AdminQuoteController::class, 'resendQuote']);   // Resend quote email
            Route::post('/{order}/cancel-quote', [AdminQuoteController::class, 'cancelQuote']);   // Cancel/void quote
            
            // Package Management
            Route::put('/{order}/items/{item}/arrived', [AdminOrderItemController::class, 'markArrived']); // Mark package arrived
        });
        
        // Package/Item Management
        Route::prefix('packages')->group(function () {
            Route::get('/', [AdminOrderItemController::class, 'index']);                          // All packages
            Route::get('/pending', [AdminOrderItemController::class, 'pending']);                 // Not yet arrived
            Route::get('/missing-weight', [AdminOrderItemController::class, 'missingWeight']);    // Need weighing
            Route::get('/{item}', [AdminOrderItemController::class, 'show']);                     // Package details
            Route::put('/{item}', [AdminOrderItemController::class, 'update']);                   // Update package info
            Route::get('/{item}/proof', [AdminOrderItemController::class, 'viewProof']);          // View proof of purchase
        });
        
        // Customer Management
        Route::prefix('customers')->group(function () {
            Route::get('/', [AdminCustomerController::class, 'index']);                           // List all customers
            Route::get('/{customer}', [AdminCustomerController::class, 'show']);                  // Customer details
            Route::get('/{customer}/orders', [AdminCustomerController::class, 'orders']);         // Customer's orders
        });
    });
});

// Fallback route
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'Endpoint not found'
    ], 404);
});