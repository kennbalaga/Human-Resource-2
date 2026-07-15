<?php

$latitude = env('ATTENDANCE_OFFICE_LATITUDE');
$longitude = env('ATTENDANCE_OFFICE_LONGITUDE');

return [
    'google_maps_api_key' => env('GOOGLE_MAPS_API_KEY'),

    'default_location' => [
        'name' => env('ATTENDANCE_OFFICE_NAME', 'Main Hospital'),
        'address' => env('ATTENDANCE_OFFICE_ADDRESS', 'Dr. Jose N. Rodriguez Memorial Hospital and Sanitarium'),
        'latitude' => filled($latitude) ? (float) $latitude : null,
        'longitude' => filled($longitude) ? (float) $longitude : null,
        'radius_meters' => (int) env('ATTENDANCE_GEOFENCE_RADIUS', 200),
        'geofence_enabled' => (bool) env('ATTENDANCE_GEOFENCE_ENABLED', false),
        'timezone' => env('ATTENDANCE_TIMEZONE', 'Asia/Manila'),
        'work_start_time' => env('ATTENDANCE_WORK_START', '08:00'),
        'work_end_time' => env('ATTENDANCE_WORK_END', '17:00'),
        'grace_period_minutes' => (int) env('ATTENDANCE_GRACE_MINUTES', 15),
        'break_minutes' => (int) env('ATTENDANCE_BREAK_MINUTES', 60),
    ],

    'report_max_days' => 92,
];
