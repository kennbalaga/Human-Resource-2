<?php

/*
|--------------------------------------------------------------------------
| Burnout risk indicator
|--------------------------------------------------------------------------
|
| A non-medical workload and rest indicator built only from records this
| system already keeps: attendance, the published roster, and leave. It does
| not diagnose anything, and the defaults below are application starting
| points for hospital HR to review, not hospital policy.
|
| Each factor scores nothing at or below `from` and its full `points` at or
| above `to`, rising in a straight line between the two. A floor rather than
| zero is the point: forty hours a week is a normal week, not a small amount
| of burnout, so the hours factor only starts counting past it. The points
| add up to 100.
|
*/

return [
    // How many days of history one assessment reads. The trend compares this
    // window with the one immediately before it.
    'window_days' => (int) env('BURNOUT_WINDOW_DAYS', 28),

    // Leave is sparse, so it is read over a longer stretch than the workload.
    'leave_lookback_days' => (int) env('BURNOUT_LEAVE_LOOKBACK_DAYS', 90),

    'moderate_score' => (int) env('BURNOUT_MODERATE_SCORE', 35),
    'high_score' => (int) env('BURNOUT_HIGH_SCORE', 60),

    // A change smaller than this, in points, is reported as steady rather
    // than as rising or easing.
    'trend_threshold' => (int) env('BURNOUT_TREND_THRESHOLD', 5),

    'factors' => [
        'weekly_hours' => ['label' => 'Average weekly hours', 'unit' => 'hours', 'from' => 40, 'to' => 56, 'points' => 25],
        'overtime_hours' => ['label' => 'Overtime', 'unit' => 'hours', 'from' => 0, 'to' => 16, 'points' => 20],
        'work_streak' => ['label' => 'Longest run of workdays', 'unit' => 'days', 'from' => 5, 'to' => 8, 'points' => 15],
        'night_shifts' => ['label' => 'Night shifts', 'unit' => 'shifts', 'from' => 4, 'to' => 12, 'points' => 10],
        'short_rest' => ['label' => 'Quick returns under the minimum rest', 'unit' => 'times', 'from' => 0, 'to' => 3, 'points' => 10],
        'days_since_leave' => ['label' => 'Days since last leave', 'unit' => 'days', 'from' => 90, 'to' => 180, 'points' => 10],
        'unplanned_leave' => ['label' => 'Sick and emergency leave', 'unit' => 'requests', 'from' => 1, 'to' => 4, 'points' => 10],
    ],

    // Leave types counted as unplanned absence by the factor above.
    'unplanned_leave_codes' => ['SICK', 'EMER'],

    // The type the staff card points at when it suggests taking time off.
    'vacation_leave_code' => 'VAC',

    /*
    | Snapshots are derived data: they can always be recomputed from the
    | records above, so unlike attendance or leave they carry no legal
    | retention duty of their own. They are also a running record of how
    | close each person was to burning out, which is not something to keep
    | for longer than the trend needs. The nightly snapshot deletes anything
    | older than this. See docs/DATA_PRIVACY.md.
    */
    'retention_days' => (int) env('BURNOUT_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Scheduling protection
    |--------------------------------------------------------------------------
    |
    | Stricter limits applied to employees whose current level is in `levels`.
    | The roster generators (bulk fill and the rotation assistant) keep them
    | inside these limits on their own. A reviewer who places someone beyond
    | them by hand on the roster board can still publish, but only with a
    | justification on record, the same as a consecutive-night streak.
    |
    */
    'protection' => [
        'enabled' => (bool) env('BURNOUT_PROTECTION_ENABLED', true),
        'levels' => ['high'],
        'days_off_per_week' => (int) env('BURNOUT_PROTECTED_DAYS_OFF', 2),
        'max_weekly_hours' => (int) env('BURNOUT_PROTECTED_MAX_WEEKLY_HOURS', 40),
        'max_night_shifts_per_week' => (int) env('BURNOUT_PROTECTED_MAX_NIGHTS', 2),
    ],
];
