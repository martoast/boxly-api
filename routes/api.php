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
use App\Http\Controllers\AdminOrderBoxController;
use App\Http\Controllers\AffiliateController;
use App\Http\Controllers\AdminAffiliateController;
use App\Http\Controllers\AdminCampaignController;
use App\Http\Controllers\CampaignTrackingController;
use App\Http\Controllers\EmployeeOrderController;
use App\Http\Controllers\StoreProductController;
use App\Http\Controllers\StoreCheckoutController;
use App\Http\Controllers\Admin\AdminProductController;
use App\Http\Controllers\Admin\AdminStoreController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminGenderController;
use App\Http\Controllers\Admin\AdminStoreSalesController;
use App\Http\Controllers\Admin\AdminTokenController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle']);
Route::post('/webhooks/stripe-shopping', [StripeWebhookController::class, 'handleShopping']);

// Public live FX rate (USD→MXN). 10-min cached, used by the storefront
// for the "approx in pesos" hint and by the cost-breakdown UI on
// Velonie's PR review screen.
Route::get('/fx-rate', [\App\Http\Controllers\FxRateController::class, 'show']);

// Public Affiliate Routes
Route::get('/affiliate/validate/{code}', [AffiliateController::class, 'validateCode']);

Route::get('/', function () {
    return response()->json(['status' => 'ok']);
});

// Public Product Routes (Stripe boxes)
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{priceId}', [ProductController::class, 'show']); // Added this

// Public Boxly Store Routes (anyone can browse + view product detail)
Route::prefix('store')->group(function () {
    Route::get('/products', [StoreProductController::class, 'index']);
    Route::get('/products/{slug}', [StoreProductController::class, 'show']);
    Route::get('/hero', [\App\Http\Controllers\ShopHeroController::class, 'publicShow']);
    Route::get('/categories', [StoreProductController::class, 'categories']);
    Route::get('/stores', [StoreProductController::class, 'stores']);
    Route::get('/genders', [StoreProductController::class, 'genders']);
});

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

// Campaign Tracking (public)
Route::get('/campaign/pixel/{token}', [CampaignTrackingController::class, 'pixel']);
Route::get('/campaign/click/{token}', [CampaignTrackingController::class, 'click']);

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
    // Self-issued Sanctum token for the Chrome extension. Any authenticated
    // user (admin, shopping, or customer) can mint one for themselves —
    // no admin intervention needed. Used by the extension's web-based
    // connect flow to skip the manual copy/paste of a token.
    Route::post('/me/extension-token', [\App\Http\Controllers\ExtensionTokenController::class, 'issue']);

    Route::get('/user', function (Request $request) {
        $user = $request->user();
        $response = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'user_type' => $user->user_type,
            'preferred_language' => $user->preferred_language,
            'role' => $user->role,
            'team' => $user->team,
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'is_affiliate' => $user->isAffiliate(),
            'total_orders' => $user->orders()->count(),
            'form_1583_completed_at' => $user->form_1583_completed_at,
        ];

        // Include affiliate data if user is an affiliate
        if ($user->isAffiliate()) {
            $affiliate = $user->affiliate;
            $response['affiliate'] = [
                'id' => $affiliate->id,
                'affiliate_code' => $affiliate->affiliate_code,
                'referral_link' => $affiliate->referral_link,
                'status' => $affiliate->status,
                'total_earnings' => (float) $affiliate->total_earnings,
                'pending_earnings' => $affiliate->pending_earnings,
            ];
        }

        return $response;
    });

    // Affiliate Portal Routes
    Route::prefix('affiliate')->group(function () {
        Route::post('/become', [AffiliateController::class, 'become']);
        Route::get('/dashboard', [AffiliateController::class, 'dashboard']);
        Route::get('/referrals', [AffiliateController::class, 'referrals']);
        Route::get('/conversions', [AffiliateController::class, 'conversions']);
        Route::get('/payouts', [AffiliateController::class, 'payouts']);
        Route::put('/profile', [AffiliateController::class, 'updateProfile']);
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
        Route::put('/{purchaseRequest}', [PurchaseRequestController::class, 'update']);
    });

    // Boxly Store — checkout flows directly into the assisted Purchase Request pipeline
    Route::post('/store/checkout', [StoreCheckoutController::class, 'create']);

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
            Route::post('/', [AdminPurchaseRequestController::class, 'store']);

            Route::delete('/bulk', [AdminPurchaseRequestController::class, 'bulkDestroy']);
            Route::put('/bulk-status', [AdminPurchaseRequestController::class, 'bulkUpdateStatus']);
            Route::post('/merge', [AdminPurchaseRequestController::class, 'mergePurchaseRequests']);
            Route::get('/{purchaseRequest}', [AdminPurchaseRequestController::class, 'show']);

            Route::put('/{purchaseRequest}', [AdminPurchaseRequestController::class, 'update']);
            Route::delete('/{purchaseRequest}', [AdminPurchaseRequestController::class, 'destroy']);

            Route::post('/{purchaseRequest}/quote', [AdminPurchaseRequestController::class, 'createQuote']);
            Route::post('/{purchaseRequest}/mark-purchased', [AdminPurchaseRequestController::class, 'markAsPurchased']);
            Route::put('/{purchaseRequest}/reject', [AdminPurchaseRequestController::class, 'reject']);

            // Per-item stock verification — Velonie marks each line available/unavailable
            // before quoting a store-source PR. Available items get billed via Stripe;
            // unavailable items stay visible on the PR but are excluded from the invoice.
            Route::put('/{purchaseRequest}/items/{item}/stock-status', [AdminPurchaseRequestController::class, 'updateItemStockStatus']);

            // Per-item cost breakdown — Velonie enters tax + shipping + commission %
            // (in USD, what the source store actually charged her at checkout).
            // Marking via this endpoint also flips the item to "available".
            Route::put('/{purchaseRequest}/items/{item}/cost-breakdown', [AdminPurchaseRequestController::class, 'updateItemCostBreakdown']);
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
            Route::get('/export', [AdminOrderController::class, 'export']);
            Route::get('/ready-to-process', [AdminOrderController::class, 'readyToProcess']);
            Route::get('/ready-to-ship', [AdminOrderController::class, 'readyToShip']);
            Route::get('/ready-for-quote', [AdminQuoteController::class, 'ordersReadyForQuote']);

            Route::delete('/bulk', [AdminOrderController::class, 'bulkDestroy']);
            Route::post('/merge', [AdminOrderController::class, 'mergeOrders']);

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

            Route::post('/{order}/consolidate', [AdminOrderController::class, 'consolidateOrder']);
            Route::post('/{order}/mark-consolidation-paid', [AdminOrderController::class, 'markConsolidationPaid']);
            Route::post('/{order}/ship', [AdminOrderController::class, 'shipOrder']);
            Route::get('/{order}/gia', [AdminOrderController::class, 'viewGia']);
            Route::post('/{order}/arrival-image', [AdminOrderController::class, 'uploadArrivalImage']);

            Route::put('/{order}/items/mark-all-arrived', [AdminOrderItemController::class, 'markAllArrived']);
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
            Route::get('/export', [AdminCustomerController::class, 'export']);
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

        Route::prefix('boxes')->group(function () {
            Route::get('/', [AdminOrderBoxController::class, 'index']);
            Route::get('/{box}', [AdminOrderBoxController::class, 'show']);
        });

        // Sanctum personal access tokens — for CLI / service auth
        Route::get('/users/{user}/cli-tokens',           [AdminTokenController::class, 'index']);
        Route::post('/users/{user}/cli-tokens',          [AdminTokenController::class, 'store']);
        Route::delete('/users/{user}/cli-tokens/{tokenId}', [AdminTokenController::class, 'destroy']);

        Route::prefix('affiliates')->group(function () {
            Route::get('/', [AdminAffiliateController::class, 'index']);
            Route::post('/', [AdminAffiliateController::class, 'store']);
            Route::get('/{affiliate}', [AdminAffiliateController::class, 'show']);
            Route::put('/{affiliate}', [AdminAffiliateController::class, 'update']);
            Route::delete('/{affiliate}', [AdminAffiliateController::class, 'destroy']);
            Route::get('/{affiliate}/conversions', [AdminAffiliateController::class, 'conversions']);
            Route::get('/{affiliate}/payouts', [AdminAffiliateController::class, 'payouts']);
            Route::post('/{affiliate}/record-payout', [AdminAffiliateController::class, 'recordPayout']);
        });

        // Boxly Store — Stores (brands)
        Route::prefix('stores')->group(function () {
            Route::get('/', [AdminStoreController::class, 'index']);
            Route::post('/', [AdminStoreController::class, 'store']);
            Route::get('/{store}', [AdminStoreController::class, 'show']);
            Route::put('/{store}', [AdminStoreController::class, 'update']);
            Route::delete('/{store}', [AdminStoreController::class, 'destroy']);
            Route::post('/{store}/logo', [AdminStoreController::class, 'uploadLogo']);
            Route::post('/{store}/cover-image', [AdminStoreController::class, 'uploadCoverImage']);
        });

        // Boxly Store — Categories
        Route::prefix('categories')->group(function () {
            Route::get('/', [AdminCategoryController::class, 'index']);
            Route::post('/', [AdminCategoryController::class, 'store']);
            Route::get('/{category}', [AdminCategoryController::class, 'show']);
            Route::put('/{category}', [AdminCategoryController::class, 'update']);
            Route::delete('/{category}', [AdminCategoryController::class, 'destroy']);
            Route::post('/{category}/image', [AdminCategoryController::class, 'uploadImage']);
        });

        // Boxly Store — Genders
        Route::prefix('genders')->group(function () {
            Route::get('/', [AdminGenderController::class, 'index']);
            Route::post('/', [AdminGenderController::class, 'store']);
            Route::get('/{gender}', [AdminGenderController::class, 'show']);
            Route::put('/{gender}', [AdminGenderController::class, 'update']);
            Route::delete('/{gender}', [AdminGenderController::class, 'destroy']);
            Route::post('/{gender}/image', [AdminGenderController::class, 'uploadImage']);
        });

        // Boxly Store — Sales (read-only view over store-checkout PRs)
        Route::prefix('store-sales')->group(function () {
            Route::get('/', [AdminStoreSalesController::class, 'index']);
            Route::get('/stats', [AdminStoreSalesController::class, 'stats']);
        });

        // Boxly Store — Admin Product Management
        Route::prefix('products')->group(function () {
            Route::get('/', [AdminProductController::class, 'index']);
            Route::post('/', [AdminProductController::class, 'store']);
            Route::delete('/bulk', [AdminProductController::class, 'bulkDestroy']);
            Route::put('/bulk-restore', [AdminProductController::class, 'bulkRestore']);
            Route::delete('/bulk-force', [AdminProductController::class, 'bulkForceDestroy']);
            Route::post('/bulk-categorize', [AdminProductController::class, 'bulkCategorize']);
            Route::post('/bulk-gender', [AdminProductController::class, 'bulkGender']);
            Route::get('/expiring', [AdminProductController::class, 'expiring']);
            Route::get('/{product}', [AdminProductController::class, 'show']);
            Route::put('/{product}', [AdminProductController::class, 'update']);
            Route::delete('/{product}', [AdminProductController::class, 'destroy']);
            Route::delete('/{product}/force', [AdminProductController::class, 'forceDestroy']);
            Route::post('/{product}/images', [AdminProductController::class, 'uploadImages']);
            Route::delete('/{product}/images/{index}', [AdminProductController::class, 'deleteImage']);
            // Variants
            Route::post('/{product}/variants', [AdminProductController::class, 'addVariant']);
            Route::post('/{product}/variants/sync', [AdminProductController::class, 'syncVariants']);
            Route::delete('/{product}/variants/{variant}', [AdminProductController::class, 'deleteVariant']);
        });

        // Editable storefront hero — single active campaign at a time.
        Route::prefix('store-hero')->group(function () {
            Route::get('/', [\App\Http\Controllers\ShopHeroController::class, 'show']);
            Route::put('/', [\App\Http\Controllers\ShopHeroController::class, 'update']);
            Route::post('/image', [\App\Http\Controllers\ShopHeroController::class, 'uploadImage']);
        });

        Route::prefix('campaigns')->group(function () {
            Route::get('/', [AdminCampaignController::class, 'index']);
            Route::post('/', [AdminCampaignController::class, 'store']);
            Route::get('/preview-audience', [AdminCampaignController::class, 'previewAudience']);
            Route::get('/{campaign}', [AdminCampaignController::class, 'show']);
            Route::put('/{campaign}', [AdminCampaignController::class, 'update']);
            Route::delete('/{campaign}', [AdminCampaignController::class, 'destroy']);
            Route::post('/{campaign}/start', [AdminCampaignController::class, 'start']);
            Route::post('/{campaign}/pause', [AdminCampaignController::class, 'pause']);
            Route::post('/{campaign}/resume', [AdminCampaignController::class, 'resume']);
            Route::post('/{campaign}/cancel', [AdminCampaignController::class, 'cancel']);
            Route::get('/{campaign}/recipients', [AdminCampaignController::class, 'recipients']);
            Route::get('/{campaign}/preview-recipients', [AdminCampaignController::class, 'previewRecipients']);
        });

    });

    /*
    |--------------------------------------------------------------------------
    | Employee Routes (Mauricio — warehouse operations)
    |--------------------------------------------------------------------------
    */
    Route::middleware('employee')->prefix('employee')->group(function () {
        Route::get('/orders', [EmployeeOrderController::class, 'index']);
        Route::get('/orders/{order}', [EmployeeOrderController::class, 'show']);
        Route::post('/orders/{order}/arrival-images', [EmployeeOrderController::class, 'uploadArrivalImages']);
    });

    /*
    |--------------------------------------------------------------------------
    | Shopping Team Routes (Velonie — storefront CRUD + PR fulfillment)
    | Mounts the existing admin controllers; gated so admins (full visibility)
    | and shopping employees both pass.
    |--------------------------------------------------------------------------
    */
    Route::middleware('shopping')->prefix('shopping')->group(function () {

        Route::prefix('purchase-requests')->group(function () {
            Route::get('/', [AdminPurchaseRequestController::class, 'index']);
            Route::post('/', [AdminPurchaseRequestController::class, 'store']);

            Route::delete('/bulk', [AdminPurchaseRequestController::class, 'bulkDestroy']);
            Route::put('/bulk-status', [AdminPurchaseRequestController::class, 'bulkUpdateStatus']);
            Route::post('/merge', [AdminPurchaseRequestController::class, 'mergePurchaseRequests']);
            Route::get('/{purchaseRequest}', [AdminPurchaseRequestController::class, 'show']);

            Route::put('/{purchaseRequest}', [AdminPurchaseRequestController::class, 'update']);
            Route::delete('/{purchaseRequest}', [AdminPurchaseRequestController::class, 'destroy']);

            Route::post('/{purchaseRequest}/quote', [AdminPurchaseRequestController::class, 'createQuote']);
            Route::post('/{purchaseRequest}/mark-purchased', [AdminPurchaseRequestController::class, 'markAsPurchased']);
            Route::put('/{purchaseRequest}/reject', [AdminPurchaseRequestController::class, 'reject']);

            // Per-item stock verification — Velonie marks each line available/unavailable
            // before quoting a store-source PR. Available items get billed via Stripe;
            // unavailable items stay visible on the PR but are excluded from the invoice.
            Route::put('/{purchaseRequest}/items/{item}/stock-status', [AdminPurchaseRequestController::class, 'updateItemStockStatus']);

            // Per-item cost breakdown — Velonie enters tax + shipping + commission %
            // (in USD, what the source store actually charged her at checkout).
            // Marking via this endpoint also flips the item to "available".
            Route::put('/{purchaseRequest}/items/{item}/cost-breakdown', [AdminPurchaseRequestController::class, 'updateItemCostBreakdown']);
        });

        Route::prefix('products')->group(function () {
            Route::get('/', [AdminProductController::class, 'index']);
            Route::post('/', [AdminProductController::class, 'store']);
            Route::delete('/bulk', [AdminProductController::class, 'bulkDestroy']);
            Route::put('/bulk-restore', [AdminProductController::class, 'bulkRestore']);
            Route::delete('/bulk-force', [AdminProductController::class, 'bulkForceDestroy']);
            Route::post('/bulk-categorize', [AdminProductController::class, 'bulkCategorize']);
            Route::post('/bulk-gender', [AdminProductController::class, 'bulkGender']);
            Route::get('/expiring', [AdminProductController::class, 'expiring']);
            Route::get('/{product}', [AdminProductController::class, 'show']);
            Route::put('/{product}', [AdminProductController::class, 'update']);
            Route::delete('/{product}', [AdminProductController::class, 'destroy']);
            Route::delete('/{product}/force', [AdminProductController::class, 'forceDestroy']);
            Route::post('/{product}/images', [AdminProductController::class, 'uploadImages']);
            Route::delete('/{product}/images/{index}', [AdminProductController::class, 'deleteImage']);
            Route::post('/{product}/variants', [AdminProductController::class, 'addVariant']);
            Route::post('/{product}/variants/sync', [AdminProductController::class, 'syncVariants']);
            Route::delete('/{product}/variants/{variant}', [AdminProductController::class, 'deleteVariant']);
        });

        // Editable storefront hero — single active campaign at a time.
        Route::prefix('store-hero')->group(function () {
            Route::get('/', [\App\Http\Controllers\ShopHeroController::class, 'show']);
            Route::put('/', [\App\Http\Controllers\ShopHeroController::class, 'update']);
            Route::post('/image', [\App\Http\Controllers\ShopHeroController::class, 'uploadImage']);
        });

        Route::prefix('stores')->group(function () {
            Route::get('/', [AdminStoreController::class, 'index']);
            Route::post('/', [AdminStoreController::class, 'store']);
            Route::get('/{store}', [AdminStoreController::class, 'show']);
            Route::put('/{store}', [AdminStoreController::class, 'update']);
            Route::delete('/{store}', [AdminStoreController::class, 'destroy']);
            Route::post('/{store}/logo', [AdminStoreController::class, 'uploadLogo']);
            Route::post('/{store}/cover-image', [AdminStoreController::class, 'uploadCoverImage']);
        });

        Route::prefix('categories')->group(function () {
            Route::get('/', [AdminCategoryController::class, 'index']);
            Route::post('/', [AdminCategoryController::class, 'store']);
            Route::get('/{category}', [AdminCategoryController::class, 'show']);
            Route::put('/{category}', [AdminCategoryController::class, 'update']);
            Route::delete('/{category}', [AdminCategoryController::class, 'destroy']);
            Route::post('/{category}/image', [AdminCategoryController::class, 'uploadImage']);
        });

        Route::prefix('genders')->group(function () {
            Route::get('/', [AdminGenderController::class, 'index']);
            Route::post('/', [AdminGenderController::class, 'store']);
            Route::get('/{gender}', [AdminGenderController::class, 'show']);
            Route::put('/{gender}', [AdminGenderController::class, 'update']);
            Route::delete('/{gender}', [AdminGenderController::class, 'destroy']);
            Route::post('/{gender}/image', [AdminGenderController::class, 'uploadImage']);
        });

        Route::prefix('store-sales')->group(function () {
            Route::get('/', [AdminStoreSalesController::class, 'index']);
            Route::get('/stats', [AdminStoreSalesController::class, 'stats']);
        });

        // Customer lookup — needed by the "create PR for customer" form.
        // Read-only; full customer CRUD remains admin-only under /admin/customers.
        Route::get('/customers', [AdminCustomerController::class, 'index']);

        Route::prefix('campaigns')->group(function () {
            Route::get('/', [AdminCampaignController::class, 'index']);
            Route::post('/', [AdminCampaignController::class, 'store']);
            Route::get('/preview-audience', [AdminCampaignController::class, 'previewAudience']);
            Route::get('/{campaign}', [AdminCampaignController::class, 'show']);
            Route::put('/{campaign}', [AdminCampaignController::class, 'update']);
            Route::delete('/{campaign}', [AdminCampaignController::class, 'destroy']);
            Route::post('/{campaign}/start', [AdminCampaignController::class, 'start']);
            Route::post('/{campaign}/pause', [AdminCampaignController::class, 'pause']);
            Route::post('/{campaign}/resume', [AdminCampaignController::class, 'resume']);
            Route::post('/{campaign}/cancel', [AdminCampaignController::class, 'cancel']);
            Route::get('/{campaign}/recipients', [AdminCampaignController::class, 'recipients']);
            Route::get('/{campaign}/preview-recipients', [AdminCampaignController::class, 'previewRecipients']);
        });
    });
});

Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'Endpoint not found'
    ], 404);
});