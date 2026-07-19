<?php

return [
    'timezone' => 'Asia/Manila',
    'max_recurrence_days' => (int) env('SCHEDULE_MAX_RECURRENCE_DAYS', 180),
    'calendar_week_starts_on' => 1,
];
