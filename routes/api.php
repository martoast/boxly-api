<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderItemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AdminOrderController;
use App\Http\Controllers\AdminOrderItemController;
use App\Http\Controllers\AdminCustomerController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CheckoutController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Stripe webhooks (no auth needed)
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);

// Public routes
Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// Products endpoint (public for pricing page)
Route::get('/products', [ProductController::class, 'index']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // User info
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    
    // Checkout
    Route::post('/checkout', [CheckoutController::class, 'createCheckout']);
    
    // Profile
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::get('/dashboard', [ProfileController::class, 'dashboard']);
    });
    
    // Orders
    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::get('/by-session/{sessionId}', [OrderController::class, 'findBySession']);
        Route::get('/collecting', [OrderController::class, 'collecting']);
        Route::get('/{order}', [OrderController::class, 'show']);
        Route::put('/{order}', [OrderController::class, 'update']);
        Route::delete('/{order}', [OrderController::class, 'destroy']);
        Route::put('/{order}/complete', [OrderController::class, 'complete']);
        Route::put('/{order}/reopen', [OrderController::class, 'reopen']);
        Route::get('/{order}/tracking', [OrderController::class, 'tracking']);
        
        // Order Items
        Route::post('/{order}/items', [OrderItemController::class, 'store']);
        Route::put('/{order}/items/{item}', [OrderItemController::class, 'update']);
        Route::delete('/{order}/items/{item}', [OrderItemController::class, 'destroy']);
    });
    
    // Admin routes
    Route::middleware('admin')->prefix('admin')->group(function () {
        
        // Dashboard
        Route::get('/dashboard', [AdminOrderController::class, 'dashboard']);
        
        // Orders
        Route::prefix('orders')->group(function () {
            Route::get('/', [AdminOrderController::class, 'index']);
            Route::get('/ready-to-ship', [AdminOrderController::class, 'readyToShip']);
            Route::get('/{order}', [AdminOrderController::class, 'show']);
            Route::put('/{order}/status', [AdminOrderController::class, 'updateStatus']);
            
            // Order Items
            Route::put('/{order}/items/{item}/arrived', [AdminOrderItemController::class, 'markArrived']);
        });
        
        // Package Management
        Route::prefix('packages')->group(function () {
            Route::get('/', [AdminOrderItemController::class, 'index']);
            Route::get('/pending', [AdminOrderItemController::class, 'pending']);
            Route::get('/missing-weight', [AdminOrderItemController::class, 'missingWeight']);
            Route::get('/{item}', [AdminOrderItemController::class, 'show']); // NEW
            Route::put('/{item}', [AdminOrderItemController::class, 'update']); // NEW
        });
        
        // Customers
        Route::prefix('customers')->group(function () {
            Route::get('/', [AdminCustomerController::class, 'index']);
            Route::get('/{customer}', [AdminCustomerController::class, 'show']);
            Route::get('/{customer}/orders', [AdminCustomerController::class, 'orders']);
            Route::get('/{customer}/collecting-orders', [AdminCustomerController::class, 'collectingOrders']);
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