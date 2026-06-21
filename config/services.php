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

    /**
     * ScraperAPI — bypasses Cloudflare on protected store pages so the stock-check
     * cron can still reach .json/.js endpoints on stores like YoungLA, Gymshark, Alo.
     */
    'scraperapi' => [
        'key' => env('SCRAPERAPI_KEY'),
        // Delivery ZIP for retailer pricing/stock (Amazon/Walmart structured).
        // Defaults to the San Ysidro / San Diego receiving warehouse.
        'zip' => env('SEARCH_ZIP', '92173'),
    ],

    /**
     * SerpAPI — fast, reliable Google Shopping results (the assistant's primary
     * universal product-search engine; ScraperAPI is the fallback).
     */
    'serpapi' => [
        'key' => env('SERPAPI_KEY'),
        // City-level location for Google Shopping — pinned to the San Diego
        // warehouse area so prices/availability match where goods land.
        'location' => env('SEARCH_LOCATION', 'San Diego, California, United States'),
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
        'default_percent' => env('BOXLY_COMMISSION_PERCENT', 10),
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