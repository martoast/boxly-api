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
use App\Http\Controllers\OperationsBoardController;
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
use App\Http\Controllers\ShoppingTripsController;
use App\Http\Controllers\Admin\AdminShoppingTripsController;
use App\Http\Controllers\Admin\AdminStoreController;
use App\Http\Controllers\Admin\AdminStarterPromptController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminKnowledgeController;
use App\Http\Controllers\Admin\AdminPurchasedProductController;
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

// Public product extraction for the AI shopping assistant (rate-limited).
Route::post('/products/extract', [\App\Http\Controllers\ProductExtractController::class, 'extract'])
    ->middleware('throttle:30,1');
Route::post('/products/store-feed', [\App\Http\Controllers\ProductExtractController::class, 'storeFeed'])
    ->middleware('throttle:30,1');
Route::post('/products/search', [\App\Http\Controllers\ProductExtractController::class, 'search'])
    ->middleware('throttle:30,1');
Route::post('/products/web-search', [\App\Http\Controllers\ProductExtractController::class, 'webSearch'])
    ->middleware('throttle:30,1');
Route::post('/products/details', [\App\Http\Controllers\ProductExtractController::class, 'details'])
    ->middleware('throttle:60,1');
Route::post('/products/page', [\App\Http\Controllers\ProductExtractController::class, 'page'])
    ->middleware('throttle:60,1');

// AI-search usage analytics — best-effort logging from the search UI.
Route::post('/search-events', [\App\Http\Controllers\SearchEventController::class, 'store'])
    ->middleware('throttle:120,1');

// Knowledge wiki for the AI assistant — published articles, read-only. Consumed
// by the Nuxt assistant server (no DB there) to answer business questions.
Route::get('/knowledge', [\App\Http\Controllers\KnowledgeController::class, 'index'])
    ->middleware('throttle:120,1');

// Starter prompt cards for the shopping assistant empty state (read-only, public).
Route::get('/starter-prompts', [\App\Http\Controllers\StarterPromptController::class, 'index'])
    ->middleware('throttle:120,1');

Route::middleware(['web'])->group(function () {
    Route::get('/auth/{provider}/redirect', AuthSocialRedirectController::class)
        ->whereIn('provider', ['google', 'facebook']);
    Route::get('/auth/{provider}/callback', AuthSocialCallbackController::class)
        ->whereIn('provider', ['google', 'facebook']);

    // Public inline account creation from the chat assistant. In the web group
    // so Auth::login sets the SPA session cookie. Rate-limited.
    Route::post('/auth/chat-register', [\App\Http\Controllers\Auth\ChatRegisterController::class, 'store'])
        ->middleware('throttle:10,1');
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

    // Self-issued Sanctum token for the Boxly MCP server — lets a customer
    // connect their account to their own AI (Claude Code / Desktop). Distinct
    // token name from the extension so the two connections don't clobber each
    // other. Revoke disconnects the AI.
    Route::post('/me/mcp-token', [\App\Http\Controllers\McpTokenController::class, 'issue']);
    Route::delete('/me/mcp-token', [\App\Http\Controllers\McpTokenController::class, 'revoke']);
    // Ephemeral token for the in-app AI chat (separate from the MCP token).
    Route::post('/me/chat-token', [\App\Http\Controllers\McpTokenController::class, 'chatToken']);

    // AI shopping assistant — chat threads (history sidebar + resume)
    Route::prefix('conversations')->group(function () {
        Route::get('/', [\App\Http\Controllers\ConversationController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\ConversationController::class, 'store']);
        Route::post('/claim', [\App\Http\Controllers\ConversationController::class, 'claim']);
        Route::get('/{conversation}', [\App\Http\Controllers\ConversationController::class, 'show']);
        Route::patch('/{conversation}', [\App\Http\Controllers\ConversationController::class, 'update']);
        Route::delete('/{conversation}', [\App\Http\Controllers\ConversationController::class, 'destroy']);
        Route::post('/{conversation}/messages', [\App\Http\Controllers\ConversationController::class, 'addMessages']);
    });

    // AI shopping assistant — learned shopping profile (cross-chat memory)
    Route::get('/me/shopping-profile', [\App\Http\Controllers\ShoppingProfileController::class, 'show']);
    Route::put('/me/shopping-profile', [\App\Http\Controllers\ShoppingProfileController::class, 'update']);

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
        // New: in-person shopping flow — customer schedules a Boxly team
        // member to physically shop at Las Americas Outlets on their behalf.
        // No payment at submission; quote happens after the trip.
        Route::post('/in-person', [PurchaseRequestController::class, 'storeInPerson']);
        Route::get('/{purchaseRequest}', [PurchaseRequestController::class, 'show']);
        Route::put('/{purchaseRequest}', [PurchaseRequestController::class, 'update']);
        // Re-issue a Stripe Checkout URL for an in-person PR still in
        // awaiting_deposit (customer cancelled or session expired).
        Route::post('/{purchaseRequest}/deposit-checkout', [PurchaseRequestController::class, 'createDepositCheckout']);
    });

    // In-person flow read-only endpoints — open trips for the calendar
    // picker, mall-flagged stores for the store-multiselect.
    Route::prefix('shopping-trips')->group(function () {
        Route::get('/availability', [ShoppingTripsController::class, 'availability']);
        Route::get('/in-person-stores', [ShoppingTripsController::class, 'inPersonStores']);
        Route::get('/categories', [ShoppingTripsController::class, 'categories']);
        Route::post('/book', [\App\Http\Controllers\ShoppingTripBookingController::class, 'store']);
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

        // Order-level proof of purchase (multiple files for the whole order)
        Route::post('/{order}/proofs', [OrderController::class, 'uploadProofs']);
        Route::delete('/{order}/proofs/{index}', [OrderController::class, 'deleteProof'])->where('index', '[0-9]+');
    });
    
    /*
    |--------------------------------------------------------------------------
    | Authenticated Admin Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('admin')->prefix('admin')->group(function () {
        
        // AI search usage analytics dashboard
        Route::get('/ai-search/stats', [\App\Http\Controllers\SearchEventController::class, 'stats']);
        Route::get('/ai-search/queries', [\App\Http\Controllers\SearchEventController::class, 'queries']);
        Route::get('/ai-search/export', [\App\Http\Controllers\SearchEventController::class, 'export']);

        Route::get('/dashboard', [UnifiedAdminDashboardController::class, 'index']);
        Route::get('/dashboard/time-series', [UnifiedAdminDashboardController::class, 'timeSeries']);
        Route::get('/dashboard/v3/overview', [UnifiedAdminDashboardController::class, 'v3Overview']);
        Route::get('/dashboard/v3/revenue-series', [UnifiedAdminDashboardController::class, 'v3RevenueSeries']);
        Route::get('/dashboard/v3/geographic', [UnifiedAdminDashboardController::class, 'v3Geographic']);
        Route::post('/dashboard/manual-metrics', [UnifiedAdminDashboardController::class, 'updateManualMetrics']);
        Route::get('/dashboard/manual-metrics', [UnifiedAdminDashboardController::class, 'getManualMetrics']);
        Route::delete('/admin/dashboard/manual-metrics', [UnifiedAdminDashboardController::class, 'deleteManualMetrics']);

        // Weekly Operations Board — warehouse source of truth
        Route::get('/operations-board', [OperationsBoardController::class, 'index']);
        Route::get('/operations-board/warehouse-list', [OperationsBoardController::class, 'warehouseList']);

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

            // Unified per-item edit (price, quantity, stock_status, notes),
            // add, and delete — the redesigned detail page uses these
            // instead of the older stock-status / cost-breakdown endpoints
            // above. All three are gated up through `paid` so admins can
            // adjust the line-up if the customer substitutes after payment;
            // once `purchased` the items have become an Order and are locked.
            Route::post('/{purchaseRequest}/items', [AdminPurchaseRequestController::class, 'addItem']);
            Route::put('/{purchaseRequest}/items/{item}', [AdminPurchaseRequestController::class, 'updateItem']);
            Route::delete('/{purchaseRequest}/items/{item}', [AdminPurchaseRequestController::class, 'deleteItem']);
        });

        // In-person shopping trip schedule — admin CRUD that powers the
        // customer-facing date picker on /shop/in-person.
        Route::prefix('shopping-trips')->group(function () {
            Route::get('/', [AdminShoppingTripsController::class, 'index']);
            Route::post('/', [AdminShoppingTripsController::class, 'store']);
            Route::get('/{shoppingTrip}', [AdminShoppingTripsController::class, 'show']);
            Route::put('/{shoppingTrip}', [AdminShoppingTripsController::class, 'update']);
            Route::delete('/{shoppingTrip}', [AdminShoppingTripsController::class, 'destroy']);
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
            // Operations Board reschedule — set/clear ship date on a consolidated order
            Route::post('/{order}/ship-date', [OperationsBoardController::class, 'updateShipDate']);
            Route::post('/{order}/mark-consolidation-paid', [AdminOrderController::class, 'markConsolidationPaid']);
            Route::post('/{order}/ship', [AdminOrderController::class, 'shipOrder']);
            Route::get('/{order}/gia', [AdminOrderController::class, 'viewGia']);
            Route::post('/{order}/arrival-image', [AdminOrderController::class, 'uploadArrivalImage']);
            Route::post('/{order}/split', [AdminOrderController::class, 'splitOrder']);

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
            Route::put('/{box}', [AdminOrderBoxController::class, 'update']);
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

        // In-person shopping — stores the team can visit at Las Americas
        Route::prefix('stores')->group(function () {
            Route::get('/', [AdminStoreController::class, 'index']);
            Route::post('/', [AdminStoreController::class, 'store']);
            Route::get('/{store}', [AdminStoreController::class, 'show']);
            Route::put('/{store}', [AdminStoreController::class, 'update']);
            Route::delete('/{store}', [AdminStoreController::class, 'destroy']);
            Route::post('/{store}/logo', [AdminStoreController::class, 'uploadLogo']);
        });

        // In-person shopping — category taxonomy for the store picker
        Route::prefix('categories')->group(function () {
            Route::get('/', [AdminCategoryController::class, 'index']);
            Route::post('/', [AdminCategoryController::class, 'store']);
            Route::get('/{category}', [AdminCategoryController::class, 'show']);
            Route::put('/{category}', [AdminCategoryController::class, 'update']);
            Route::delete('/{category}', [AdminCategoryController::class, 'destroy']);
        });

        // Knowledge wiki for the AI assistant (business Q&A)
        Route::prefix('knowledge')->group(function () {
            Route::get('/', [AdminKnowledgeController::class, 'index']);
            Route::post('/', [AdminKnowledgeController::class, 'store']);
            Route::get('/{knowledge}', [AdminKnowledgeController::class, 'show']);
            Route::put('/{knowledge}', [AdminKnowledgeController::class, 'update']);
            Route::delete('/{knowledge}', [AdminKnowledgeController::class, 'destroy']);
        });

        // Starter prompt cards shown on the shopping assistant empty state
        Route::prefix('starter-prompts')->group(function () {
            Route::get('/', [AdminStarterPromptController::class, 'index']);
            Route::post('/', [AdminStarterPromptController::class, 'store']);
            Route::get('/{starterPrompt}', [AdminStarterPromptController::class, 'show']);
            Route::put('/{starterPrompt}', [AdminStarterPromptController::class, 'update']);
            Route::delete('/{starterPrompt}', [AdminStarterPromptController::class, 'destroy']);
            Route::post('/{starterPrompt}/image', [AdminStarterPromptController::class, 'uploadImage']);
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
    | Shopping Team Routes (Velonie — PR fulfillment + in-person store/category mgmt)
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

            // Unified per-item edit, add, and delete (redesigned detail page).
            Route::post('/{purchaseRequest}/items', [AdminPurchaseRequestController::class, 'addItem']);
            Route::put('/{purchaseRequest}/items/{item}', [AdminPurchaseRequestController::class, 'updateItem']);
            Route::delete('/{purchaseRequest}/items/{item}', [AdminPurchaseRequestController::class, 'deleteItem']);
        });

        Route::prefix('stores')->group(function () {
            Route::get('/', [AdminStoreController::class, 'index']);
            Route::post('/', [AdminStoreController::class, 'store']);
            Route::get('/{store}', [AdminStoreController::class, 'show']);
            Route::put('/{store}', [AdminStoreController::class, 'update']);
            Route::delete('/{store}', [AdminStoreController::class, 'destroy']);
            Route::post('/{store}/logo', [AdminStoreController::class, 'uploadLogo']);
        });

        Route::prefix('categories')->group(function () {
            Route::get('/', [AdminCategoryController::class, 'index']);
            Route::post('/', [AdminCategoryController::class, 'store']);
            Route::get('/{category}', [AdminCategoryController::class, 'show']);
            Route::put('/{category}', [AdminCategoryController::class, 'update']);
            Route::delete('/{category}', [AdminCategoryController::class, 'destroy']);
        });

        Route::prefix('purchased-products')->group(function () {
            Route::get('/', [AdminPurchasedProductController::class, 'index']);
            Route::post('/', [AdminPurchasedProductController::class, 'store']);
            Route::get('/{purchasedProduct}', [AdminPurchasedProductController::class, 'show']);
            Route::put('/{purchasedProduct}', [AdminPurchasedProductController::class, 'update']);
            Route::delete('/{purchasedProduct}', [AdminPurchasedProductController::class, 'destroy']);
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