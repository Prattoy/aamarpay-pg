<?php

return [
    'sandbox_mode' => env('AAMARPAY_SANDBOX_MODE', true),
    'app_url'  => env('SERVER_URL') ?? env('APP_URL', 'http://localhost'),

    'sandbox' => [
        'store_id' => env('AAMARPAY_SANDBOX_STORE_ID'),
        'signature_key' => env('AAMARPAY_SANDBOX_SIGNATURE_KEY'),
        'url' => 'https://sandbox.aamarpay.com/index.php',
        'search_url' => 'https://sandbox.aamarpay.com/api/v1/trxcheck/request.php',
    ],

    'live' => [
        'store_id' => env('AAMARPAY_LIVE_STORE_ID'),
        'signature_key' => env('AAMARPAY_LIVE_SIGNATURE_KEY'),
        'url' => 'https://secure.aamarpay.com/index.php',
        'search_url' => 'https://secure.aamarpay.com/api/v1/trxcheck/request.php',
    ],

    // Non-secret configuration
    'authorized_services' => [
        [
            'name' => 'Wedding Site',
            'service_from' => 'wedding_site',
            'webhook_secret' => env('SERVICE_WEDDING_SITE_WEBHOOK_SECRET'),
            'allowed_ips' => [], // * for all, [] nothing is allowed,
        ],
        [
            'name' => 'Booking Platform',
            'service_from' => 'BookingPlatform',
            'webhook_secret' => env('SERVICE_BOOKING_WEBHOOK_SECRET'),
            'allowed_ips' => [],
        ],
    ],

    // Non-secret defaults
    'timeout' => 30, // seconds
    'retry_attempts' => 3,
    'webhook_retry_delay' => 300, // 5 minutes
];
