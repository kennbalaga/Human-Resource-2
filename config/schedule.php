<?php

return [
    'timezone' => 'Asia/Manila',
    'max_recurrence_days' => (int) env('SCHEDULE_MAX_RECURRENCE_DAYS', 180),
    'max_bulk_assignment_days' => (int) env('SCHEDULE_MAX_BULK_ASSIGNMENT_DAYS', 31),
    'max_bulk_assignment_employees' => (int) env('SCHEDULE_MAX_BULK_ASSIGNMENT_EMPLOYEES', 100),

    // A month of rostering for a large unit, sized so a reviewed roster is never
    // truncated on its way back to the server.
    'max_roster_entries' => (int) env('SCHEDULE_MAX_ROSTER_ENTRIES', 4000),
    'minimum_rest_hours' => (int) env('SCHEDULE_MINIMUM_REST_HOURS', 8),
    'calendar_week_starts_on' => 1,

    /*
    | Hard cap on consecutive scheduled workdays, checked across week
    | boundaries (unlike the days-off-per-week rule, which only counts
    | distinct dates within a single ISO week).
    */
    'max_consecutive_workdays' => (int) env('SCHEDULE_MAX_CONSECUTIVE_WORKDAYS', 6),

    /*
    | Standards the HR compliance review is judged against. These are
    | independent of the per-run overrides a manager can type into the bulk
    | scheduling form — the review checks what was actually published against
    | the hospital's standing policy, not against whatever was typed in.
    */
    'compliance' => [
        'max_hours_per_week' => (int) env('SCHEDULE_COMPLIANCE_MAX_HOURS_PER_WEEK', 48),
        'night_shift_limit' => (int) env('SCHEDULE_COMPLIANCE_NIGHT_SHIFT_LIMIT', 6),
        'days_off_per_week' => (int) env('SCHEDULE_COMPLIANCE_DAYS_OFF_PER_WEEK', 1),
    ],
];
