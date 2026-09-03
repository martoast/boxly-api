<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => [
        'auth/*', 
        'csrf-cookie', 
        'user',
        'orders',
        'orders/*',
        'admin/*',
        'admin/customers',
        'admin/campaigns',
        'profile',
        'profile/*',
        'products',
        'products/*',
        'conversations',
        'conversations/*',
        'starter-prompts',
        'starter-prompts/*',
        'checkout',
        'payment-methods',
        'payment-methods/*',
        'track',
        'funnel-capture',
        'expenses',
        'expenses/*',
        'financial-dashboard',
        'financial-dashboard/*',
        'user-types',
        'shipment-tracking',
        'shipment-tracking/*',
        'purchase-requests',
        'purchase-requests/*',
        'shopping-trips/*',
        'affiliate',
        'affiliate/*',
        'campaign/*',
        'employee/*',
        'shopping/*',
        // Browser-plane live shopping: ONLY the session-scoped routes the SPA
        // calls directly (ticket mint, status). The create runs server-to-server
        // from Nuxt and the webhook is engine-to-Laravel — neither is a browser
        // surface, so neither belongs here.
        'live-shopping/sessions/*',
        'live-shopping/stores', // remote store browser: the cards are fetched by the browser
        'store/*',
        'me/*',
        'fx-rate',
        'hero',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
