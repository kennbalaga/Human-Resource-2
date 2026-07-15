<?php

return [
    'timezone' => env('SCHEDULE_TIMEZONE', env('ATTENDANCE_TIMEZONE', 'Asia/Manila')),
    'max_recurrence_days' => (int) env('SCHEDULE_MAX_RECURRENCE_DAYS', 180),
    'calendar_week_starts_on' => 1,
];
