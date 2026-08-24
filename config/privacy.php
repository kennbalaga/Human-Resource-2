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
];
