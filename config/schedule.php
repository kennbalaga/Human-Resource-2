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
];
