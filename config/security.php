<?php

return [
    'session' => [
        'warning_seconds' => (int) env('SESSION_WARNING_SECONDS', 300),
        'heartbeat_seconds' => (int) env('SESSION_HEARTBEAT_SECONDS', 300),
    ],

    'two_factor' => [
        'required_roles' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TWO_FACTOR_REQUIRED_ROLES', 'system-administrator,hr-manager,department-head')),
        ))),
    ],
];
