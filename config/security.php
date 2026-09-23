<?php

// CSP host sources accept only letters, digits, hyphens and dots in the host
// part, so a bracketed IPv6 literal such as http://[::1]:* is not valid and is
// silently discarded by browsers. vite.config.js therefore pins the dev server
// to 127.0.0.1, which these entries do cover.
$viteDevelopmentOrigins = (string) env('APP_ENV') === 'local'
    ? ' http://localhost:* http://127.0.0.1:*'
    : '';

// Kept separate from the origins above: only connect-src has a reason to name
// a websocket scheme, and listing ws:// in script-src would be meaningless.
$viteDevelopmentSockets = (string) env('APP_ENV') === 'local'
    ? ' ws://localhost:* ws://127.0.0.1:*'
    : '';

return [
    'production' => [
        // Local and test environments are never blocked regardless of this
        // value (EnforceProductionSecurity also requires APP_ENV=production).
        // The default is now "on": an environment that never set this
        // variable at all fails safe instead of silently shipping without
        // the guard. Set it to false explicitly only if you have a specific
        // reason to run production without the check.
        'enforce' => (bool) env('SECURITY_ENFORCE_PRODUCTION', true),
    ],

    'content_security_policy' => [
        // Enforced by default. The rollout this started as is over: every
        // inline handler has been moved into the bundle and the two remaining
        // inline scripts carry a nonce, so there is nothing left for
        // report-only to discover. Supported values: off, report-only, enforce.
        'mode' => env('CSP_MODE', 'enforce'),
        'report_uri' => env('CSP_REPORT_URI', '/api/v1/security/csp-report'),
        'directives' => [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            // No 'unsafe-inline'. It would permit any <script> an attacker
            // injected, which is the single thing this policy exists to stop —
            // and a browser that sees a nonce ignores 'unsafe-inline' anyway.
            // The nonce is minted per request by SecurityHeaders and stamped on
            // the Vite tags and the two hand-written inline blocks.
            "script-src 'self' 'nonce-{csp_nonce}'{$viteDevelopmentOrigins}",
            // 'unsafe-inline' is kept here and only here. Style attributes are
            // used throughout the Blade templates and a nonce cannot cover an
            // attribute, so removing it would mean rewriting the markup for a
            // far smaller prize: injected CSS cannot execute, it can only
            // restyle. The two hosts serve Font Awesome and Inter on the
            // signed-out pages.
            "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com{$viteDevelopmentOrigins}",
            "font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com",
            "img-src 'self' data: blob:",
            // The dev-server and HMR websocket origins belong to `npm run dev`
            // and were previously listed unconditionally, which shipped them in
            // the production policy too. They now follow the same env switch as
            // script-src and style-src.
            "connect-src 'self'{$viteDevelopmentOrigins}{$viteDevelopmentSockets}",
            "media-src 'self'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            'upgrade-insecure-requests',
        ],
    ],

    'attachments' => [
        'malware_scanning' => [
            // Same fail-safe pattern as session.encrypt and session.secure: an
            // environment that never set the variable gets the safe value in
            // production and the convenient one elsewhere, so a deployment
            // cannot accept attachments unscanned merely by omission.
            'enabled' => (bool) env('MALWARE_SCANNING_ENABLED', (string) env('APP_ENV') === 'production'),
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

        // How often an open screen asks the server whether it is still signed
        // in. This is what tells a device that the account has been opened
        // somewhere else, and nobody watching their own screen should have to
        // click something to find that out — so it is counted in seconds, not
        // minutes. Only visible tabs ask; a backgrounded one asks the moment
        // it is looked at again. Raise it to trade promptness for traffic.
        'heartbeat_seconds' => (int) env('SESSION_HEARTBEAT_SECONDS', 5),
    ],

    'downloads' => [
        // How long a password re-entry keeps file downloads open before the
        // next one asks again. Kept short and separate from
        // auth.password_timeout: the check exists for the browser that was
        // left signed in, and an unattended one gives nobody three hours.
        'password_timeout_seconds' => (int) env('DOWNLOAD_PASSWORD_TIMEOUT', 900),

        // Files one account may take per minute. Generous for somebody saving
        // a handful of payslips, tight for a script emptying the system.
        'per_minute' => (int) env('DOWNLOAD_RATE_LIMIT', 20),
    ],

    'two_factor' => [
        'required_roles' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TWO_FACTOR_REQUIRED_ROLES', 'system-administrator,hr-manager,department-head')),
        ))),
    ],

    'mobile' => [
        /*
         * Roles that may not use this app on a phone or a tablet at all.
         *
         * The reasoning is about what these roles can see rather than about
         * screen size. An HR manager's session reaches every employee record in
         * the hospital; a system administrator's reaches the audit log. A phone
         * is carried, lent, left on a desk and shoulder-read on a jeepney, and
         * the app lock that mitigates exactly that is a device-local PIN — good
         * enough for one employee's own payslip, not for the whole workforce.
         * So the org-wide roles are desk-bound and the phone belongs to the
         * staff who only ever see themselves.
         *
         * Setting MOBILE_RESTRICTED_ROLES to an empty value lifts the
         * restriction entirely, which is how a phone is tested against an
         * administrator account without editing code.
         */
        'restricted_roles' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MOBILE_RESTRICTED_ROLES', 'system-administrator,hr-manager,department-head')),
        ))),

        /*
         * How long a phone stays trusted to skip the authenticator code after
         * its app lock was set up. The trust is dropped the moment the lock is
         * removed, so this is only the backstop for a device that quietly stops
         * being used -- an employee's old handset in a drawer.
         */
        'trust_days' => (int) env('MOBILE_TRUST_DAYS', 180),
    ],
];
