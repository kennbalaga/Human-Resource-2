<?php

return [
    'timezone' => 'Asia/Manila',
    'standard_daily_minutes' => (int) env('WORKFORCE_STANDARD_DAILY_MINUTES', 480),
    'analytics_max_days' => (int) env('WORKFORCE_ANALYTICS_MAX_DAYS', 366),
    'leave_max_days' => (int) env('WORKFORCE_LEAVE_MAX_DAYS', 30),
    'attachment_max_kilobytes' => (int) env('WORKFORCE_ATTACHMENT_MAX_KB', 5120),
    'employee_number_auto_generate' => env('WORKFORCE_EMPLOYEE_ID_AUTO_GENERATE', true),
    'attendance_capture_mode' => env('WORKFORCE_ATTENDANCE_CAPTURE_MODE', 'hybrid'),
    'local_employee_default_password' => env('LOCAL_EMPLOYEE_DEFAULT_PASSWORD', 'ChangeMe123!'),
];
