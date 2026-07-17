<?php

return [
    'timeout_seconds' => (int) env('INTEGRATION_TIMEOUT_SECONDS', 10),
    'gemini' => [
        'enabled' => env('GEMINI_ENABLED', false),
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    ],
];
