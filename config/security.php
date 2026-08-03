<?php

// CSP host sources accept only letters, digits, hyphens and dots in the host
// part, so a bracketed IPv6 literal such as http://[::1]:* is not valid and is
// silently discarded by browsers. vite.config.js therefore pins the dev server
// to 127.0.0.1, which these entries do cover.
$viteDevelopmentOrigins = (string) env('APP_ENV') === 'local'
    ? ' http://localhost:* http://127.0.0.1:*'
    : '';

return [
    'production' => [
        // Local and test environments are never blocked. In production, turning
        // this on makes insecure runtime configuration return a generic 503.
        'enforce' => (bool) env('SECURITY_ENFORCE_PRODUCTION', false),
    ],

    'content_security_policy' => [
        // Start in report-only mode so existing screens can be observed before
        // the policy is enforced. Supported values: off, report-only, enforce.
        'mode' => env('CSP_MODE', 'report-only'),
        'report_uri' => env('CSP_REPORT_URI', '/api/v1/security/csp-report'),
        'directives' => [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "script-src 'self' 'unsafe-inline'{$viteDevelopmentOrigins}",
            "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com{$viteDevelopmentOrigins}",
            "font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com",
            "img-src 'self' data: blob:",
            "connect-src 'self' http://localhost:* http://127.0.0.1:* ws://localhost:* ws://127.0.0.1:*",
            "media-src 'self'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            'upgrade-insecure-requests',
        ],
    ],

    'attachments' => [
        'malware_scanning' => [
            'enabled' => (bool) env('MALWARE_SCANNING_ENABLED', false),
            'driver' => env('MALWARE_SCANNING_DRIVER', 'clamav'),
            'binary' => env('CLAMAV_BINARY', 'clamscan'),
            'timeout_seconds' => (int) env('CLAMAV_TIMEOUT_SECONDS', 30),
            // Production should fail closed so an unavailable scanner cannot
            // silently turn into an upload bypass.
            'fail_closed' => (bool) env('MALWARE_SCANNING_FAIL_CLOSED', true),
        ],
    ],

    'audit' => [
        'alert_admin_roles' => ['system-administrator'],
    ],

    'session' => [
        'warning_seconds' => (int) env('SESSION_WARNING_SECONDS', 300),
        'heartbeat_seconds' => (int) env('SESSION_HEARTBEAT_SECONDS', 300),
    ],

    'two_factor' => [
        'required_roles' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TWO_FACTOR_REQUIRED_ROLES', 'system-administrator,hr-manager,department-head')),
        ))),
    ],
];
