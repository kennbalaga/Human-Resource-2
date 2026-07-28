<?php

return [
    'timezone' => 'Asia/Manila',
    'max_recurrence_days' => (int) env('SCHEDULE_MAX_RECURRENCE_DAYS', 180),
    'max_bulk_assignment_days' => (int) env('SCHEDULE_MAX_BULK_ASSIGNMENT_DAYS', 31),
    'max_bulk_assignment_employees' => (int) env('SCHEDULE_MAX_BULK_ASSIGNMENT_EMPLOYEES', 100),
    'minimum_rest_hours' => (int) env('SCHEDULE_MINIMUM_REST_HOURS', 8),
    'calendar_week_starts_on' => 1,
];
