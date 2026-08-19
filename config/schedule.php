<?php

return [
    'timezone' => 'Asia/Manila',
    'max_recurrence_days' => (int) env('SCHEDULE_MAX_RECURRENCE_DAYS', 180),
    'max_bulk_assignment_days' => (int) env('SCHEDULE_MAX_BULK_ASSIGNMENT_DAYS', 31),
    'max_bulk_assignment_employees' => (int) env('SCHEDULE_MAX_BULK_ASSIGNMENT_EMPLOYEES', 100),

    // A month of rostering for a large unit, sized so a reviewed roster is never
    // truncated on its way back to the server.
    'max_roster_entries' => (int) env('SCHEDULE_MAX_ROSTER_ENTRIES', 4000),
    // 12 hours between shifts, not the 8 that a strict overlap check alone
    // would allow — enough to rule out a fatigue-driving quick-return
    // pattern like a Night Shift (10 PM–7 AM) immediately followed by a
    // Morning Shift (6 AM), which only technically avoids overlapping.
    'minimum_rest_hours' => (int) env('SCHEDULE_MINIMUM_REST_HOURS', 12),

    /*
    | Labor Code Art. 86 night-shift differential: 10% premium for each hour
    | worked in this window. Deliberately a fixed clock window, not derived
    | from any shift's own start/end time — a Night Shift running 10 PM–7 AM
    | only has 8 of its 9 hours actually inside 10 PM–6 AM; the 9th hour is
    | paid at the regular rate. Kept separate from the "is this a night
    | shift" fatigue heuristic on the Shift model, which answers a different
    | question (does this shift count toward the night-shift/consecutive-
    | night limits) and is not a wage computation.
    */
    'night_differential' => [
        'start' => env('SCHEDULE_NIGHT_DIFFERENTIAL_START', '22:00'),
        'end' => env('SCHEDULE_NIGHT_DIFFERENTIAL_END', '06:00'),
    ],
    'calendar_week_starts_on' => 1,

    /*
    | Hard cap on consecutive scheduled workdays, checked across week
    | boundaries (unlike the days-off-per-week rule, which only counts
    | distinct dates within a single ISO week).
    */
    'max_consecutive_workdays' => (int) env('SCHEDULE_MAX_CONSECUTIVE_WORKDAYS', 6),

    /*
    | Labor Code Art. 91: at least 24 consecutive hours of rest after every
    | max_consecutive_workdays. This is a hard legal floor, not a per-run
    | override — unlike minimum_rest_hours (the general inter-shift fatigue
    | gap, tunable per roster), this one is not exposed as a Step 2 field.
    */
    'weekly_rest_hours' => (int) env('SCHEDULE_WEEKLY_REST_HOURS', 24),

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
        // A per-week night-shift count alone misses a streak that crosses a
        // week boundary, so a 6/week limit with 1 day off could still let a
        // published schedule carry six consecutive nights through undetected.
        'max_consecutive_nights' => (int) env('SCHEDULE_COMPLIANCE_MAX_CONSECUTIVE_NIGHTS', 4),
    ],
];
