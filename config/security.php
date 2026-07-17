<?php

return [
    'two_factor' => [
        'required_roles' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TWO_FACTOR_REQUIRED_ROLES', 'system-administrator,hr-manager,department-head')),
        ))),
    ],
];
