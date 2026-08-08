<?php

return [
    'enabled' => env('AI_WORKFORCE_SCHEDULING_ENABLED', false),
    'gemini_explanations_enabled' => env('AI_SCHEDULING_GEMINI_EXPLANATIONS', false),
    'recommendation_ttl_minutes' => (int) env('AI_SCHEDULING_RECOMMENDATION_TTL_MINUTES', 15),
    'history_days' => (int) env('AI_SCHEDULING_HISTORY_DAYS', 28),

    /*
    | These are advisory algorithm weights, not hospital scheduling policies.
    | Hospital HR must review them before enabling this feature in production.
    */
    'weights' => [
        'eligibility' => 40,
        'weekly_workload' => 15,
        'overtime' => 10,
        'recent_assignments' => 10,
        'overnight_assignments' => 10,
        'consecutive_duties' => 5,
        'shift_preference' => 5,
        'rest_time' => 5,
    ],

    'workload_risk' => [
        'moderate_score' => (int) env('AI_SCHEDULING_MODERATE_RISK_SCORE', 35),
        'high_score' => (int) env('AI_SCHEDULING_HIGH_RISK_SCORE', 65),
        'reference_weekly_minutes' => (int) env('AI_SCHEDULING_REFERENCE_WEEKLY_MINUTES', 2400),
        'reference_overtime_minutes' => (int) env('AI_SCHEDULING_REFERENCE_OVERTIME_MINUTES', 480),
        'reference_recent_assignments' => (int) env('AI_SCHEDULING_REFERENCE_RECENT_ASSIGNMENTS', 20),
        'reference_consecutive_duties' => (int) env('AI_SCHEDULING_REFERENCE_CONSECUTIVE_DUTIES', 5),
        'reference_rest_hours' => (int) env('AI_SCHEDULING_REFERENCE_REST_HOURS', 8),
    ],
];
