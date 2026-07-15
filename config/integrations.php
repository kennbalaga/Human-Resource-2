<?php

return [
    'timeout_seconds' => (int) env('INTEGRATION_TIMEOUT_SECONDS', 10),
    'gemini' => [
        'enabled' => env('GEMINI_ENABLED', false),
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],
    'zapier' => [
        'enabled' => env('ZAPIER_ENABLED', false),
        'webhook_url' => env('ZAPIER_WEBHOOK_URL'),
        'signing_secret' => env('ZAPIER_SIGNING_SECRET'),
    ],
    'zoom' => [
        'enabled' => env('ZOOM_ENABLED', false),
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
        'user_id' => env('ZOOM_USER_ID', 'me'),
        'timezone' => env('ZOOM_TIMEZONE', 'Asia/Manila'),
    ],
];
