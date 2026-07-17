<?php

namespace App\Services\Scheduling;

use App\Models\Department;
use App\Models\Position;
use App\Models\Shift;

class ScheduleRecommendationService
{
    public function __construct(
        private readonly EmployeeEligibilityService $eligibility,
        private readonly CandidateScoringService $scoring,
        private readonly WorkloadRiskService $workloadRisk,
    ) {}

    /** @return array<string, mixed> */
    public function generate(Department $department, Position $position, Shift $shift, string $workDate): array
    {
        $analysis = $this->eligibility->analyze($department, $position, $shift, $workDate);
        $ranked = collect($this->scoring->rank($analysis['eligible'], $shift, $workDate))
            ->map(fn (array $candidate) => $candidate + [
                'workload_risk' => $this->workloadRisk->assess($candidate['metrics']),
            ])
            ->values()
            ->all();
        $averages = $this->fairnessAverages($ranked);
        $ranked = collect($ranked)->map(fn (array $candidate) => $candidate + [
            'fairness' => [
                'weekly_workload_vs_pool' => round($candidate['metrics']['weekly_workload_minutes'] - $averages['weekly_workload_minutes'], 2),
                'recent_assignments_vs_pool' => round($candidate['metrics']['recent_assignments'] - $averages['recent_assignments'], 2),
                'overnight_assignments_vs_pool' => round($candidate['metrics']['overnight_assignments'] - $averages['overnight_assignments'], 2),
            ],
        ])->all();

        return [
            'recommended' => $ranked[0] ?? null,
            'alternatives' => array_slice($ranked, 1, 2),
            'eligible' => $ranked,
            'ineligible' => $analysis['ineligible'],
            'warnings' => array_merge($analysis['warnings'], [
                'Workload risk uses initial configurable system references, is non-medical, and requires hospital HR review before production use.',
            ]),
            'notice' => 'This deterministic recommendation uses available scheduling, attendance, and workload records. HR review is required before applying it.',
        ];
    }

    /** @param array<int, array<string, mixed>> $ranked @return array<string, float> */
    private function fairnessAverages(array $ranked): array
    {
        $candidates = collect($ranked);

        return [
            'weekly_workload_minutes' => (float) $candidates->avg('metrics.weekly_workload_minutes'),
            'recent_assignments' => (float) $candidates->avg('metrics.recent_assignments'),
            'overnight_assignments' => (float) $candidates->avg('metrics.overnight_assignments'),
        ];
    }
}
