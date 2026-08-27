<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Retention windows
    |--------------------------------------------------------------------------
    |
    | Each entry, in days, controls how far back `records:prune-expired` keeps
    | data before deleting it. Null (the default — nothing set here enables
    | anything) means "don't prune this table." See docs/DATA_PRIVACY.md for
    | the proposed values and the reasoning behind them; they are proposals
    | for your organization to confirm, not decisions already made for you.
    | This config file intentionally ships with every window disabled.
    |
    */
    'retention_days' => [
        'attendance_records' => env('PRIVACY_RETAIN_ATTENDANCE_DAYS'),
        'leave_requests' => env('PRIVACY_RETAIN_LEAVE_DAYS'),
        'audit_logs' => env('PRIVACY_RETAIN_AUDIT_LOGS_DAYS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Policy document
    |--------------------------------------------------------------------------
    |
    | Backs the public /privacy-policy page. The contact block is deliberately
    | unset by default: the page must never print an invented mailbox, so the
    | view falls back to naming the office rather than an address nobody reads.
    | Fill these in for your deployment — RA 10173 requires a reachable DPO.
    |
    | `updated_at` is the date the wording last changed, not today's date. A
    | policy whose "last updated" line moves on its own tells the reader
    | nothing about whether the terms they agreed to have changed.
    |
    */
    'policy' => [
        'updated_at' => '2026-08-26',
        'dpo_name' => env('PRIVACY_DPO_NAME'),
        'dpo_email' => env('PRIVACY_DPO_EMAIL'),
        'dpo_phone' => env('PRIVACY_DPO_PHONE'),
        'office' => env(
            'PRIVACY_DPO_OFFICE',
            'Human Resource Management Office, Dr. Jose N. Rodriguez Memorial Hospital and Sanitarium, Tala, Caloocan City',
        ),
    ],
];
