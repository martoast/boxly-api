<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL') . '/auth/google/callback'),
    ],
    
    'gohighlevel' => [
        'webhook_url' => env('GOHIGHLEVEL_WEBHOOK_URL', 'https://services.leadconnectorhq.com/hooks/2Pr7Q71krQMxeqK7aWJl/webhook-trigger/cf022b90-d43b-478b-b08e-de4367dd4142'),
        'order_placed_webhook_url' => env('GOHIGHLEVEL_ORDER_PLACED_WEBHOOK_URL'),
    ],

    'aftership' => [
        'api_key' => env('AFTERSHIP_API_KEY'),
        'base_url' => env('AFTERSHIP_BASE_URL', 'https://api.aftership.com/tracking/2025-07'),
    ],

    'exchange_rate' => [
        'usd_to_mxn' => env('EXCHANGE_RATE_USD_TO_MXN', 18.00),
    ],

    /**
     * Boxly's default commission percentage applied per-item on
     * store-source PRs. The Velonie review form pre-fills this so
     * she just hits "available" for normal items; she can override
     * for premium / discounted items.
     */
    'commission' => [
        'default_percent' => env('BOXLY_COMMISSION_PERCENT', 15),
    ],

    /**
     * In-person shopping at Las Americas — flat per-store service charge
     * applied at quote time by AdminPurchaseRequestController::createQuote.
     * Shown to the customer on the store-picker step and itemized in the
     * Stripe invoice description.
     */
    'in_person' => [
        'per_store_fee_usd' => env('BOXLY_IN_PERSON_PER_STORE_FEE_USD', 10),
    ],

    'nu_bank' => [
        'beneficiary_name' => env('NU_BENEFICIARY_NAME'),
        'bank_name' => env('NU_BANK_NAME'),
        'account_number' => env('NU_ACCOUNT_NUMBER'),
    ],

    'hsbc' => [
        'beneficiary_name' => env('HSBC_BENEFICIARY_NAME'),
        'bank_name' => env('HSBC_BANK_NAME'),
        'account_number' => env('HSBC_ACCOUNT_NUMBER'),
        'clave' => env('HSBC_CLAVE'),
    ],

    /**
     * Boxly Protection — the optional per-box theft/loss/damage add-on.
     *
     * Only the PRODUCT id lives here. The amount charged is always read live
     * from Stripe at the moment of sale (see ProtectionProduct), never
     * hardcoded — the same rule the box prices follow.
     *
     * Defaulted rather than env-only on purpose: local and production share
     * one Stripe account, so a default means production needs no manual env
     * setup and cannot silently ship with protection misconfigured.
     */
    'boxly_protection' => [
        'product_id' => env('STRIPE_PROTECTION_PRODUCT_ID', 'prod_V1AEN4i1Io6J6X'),
    ],

    /**
     * Live Shopping engine — the remote browser/streaming service that drives a
     * live, conversation-attached shopping session (P1: one store, view-only).
     *
     * Laravel is the CONTROL PLANE only: it never proxies, terminates or sees
     * video. Env-only, no defaults — an unset value must mean "off", never a
     * half-configured deployment pointed at nothing.
     *
     * `enabled` is advisory; LiveShoppingEngine::configured() is the authority,
     * and it is false unless base_url AND service_secret are both present.
     *
     * The engine owns the callback destination: it maps the fixed callback_id
     * to its own configured HTTPS webhook URL. Laravel never builds one, which
     * is why there is no callback_base here.
     */
    'live_shopping_engine' => [
        'enabled'         => (bool) env('LIVE_SHOPPING_ENABLED', false),
        'base_url'        => env('LIVE_SHOPPING_BASE_URL'),
        'service_secret'  => env('LIVE_SHOPPING_SERVICE_SECRET'),
        // Which service key signs our OUTBOUND requests, so the engine can
        // rotate our credential without a flag day.
        'service_key_id'  => env('LIVE_SHOPPING_SERVICE_KEY_ID'),
        // Inbound webhook secrets, keyed by X-Boxly-Key-Id so a secret can be
        // rotated without a flag day: LIVE_SHOPPING_WEBHOOK_KEYS="k1:secret1,k2:secret2"
        'webhook_keys'    => env('LIVE_SHOPPING_WEBHOOK_KEYS'),
        'timeout'         => (int) env('LIVE_SHOPPING_TIMEOUT', 8),
        'skew'            => (int) env('LIVE_SHOPPING_SKEW', 300),
        'expiry_grace'    => (int) env('LIVE_SHOPPING_EXPIRY_GRACE', 60),
        'drain_grace'     => (int) env('LIVE_SHOPPING_DRAIN_GRACE', 30),
        // How long a terminal delivery may arrive before its own session row is
        // visible (the engine can be faster than our create round-trip).
        'orphan_horizon'  => (int) env('LIVE_SHOPPING_ORPHAN_HORIZON', 300),
        // callback_id is NOT configurable: it is the frozen literal
        // LiveShoppingEngine::CALLBACK_ID.
    ],

    /**
     * Second Stripe account — used exclusively for the Boxly Store /
     * Purchase Request payment flow. Keeps the shopping books separate
     * from the main shipping/box account (which Cashier owns).
     */
    'stripe_shopping' => [
        'key'            => env('STRIPE_SHOPPING_KEY'),
        'secret'         => env('STRIPE_SHOPPING_SECRET'),
        'webhook_secret' => env('STRIPE_SHOPPING_WEBHOOK_SECRET'),
    ],

];
