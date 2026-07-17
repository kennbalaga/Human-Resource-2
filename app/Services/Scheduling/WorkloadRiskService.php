<?php

namespace App\Services\Scheduling;

class WorkloadRiskService
{
    /** @param array<string, int|float> $metrics @return array<string, mixed> */
    public function assess(array $metrics): array
    {
        $config = config('ai_workforce_scheduling.workload_risk');
        $factors = [
            'weekly_workload' => $this->factor('Weekly workload', $metrics['weekly_workload_minutes'], $config['reference_weekly_minutes'], 35),
            'overtime' => $this->factor('Approved overtime', $metrics['overtime_minutes'], $config['reference_overtime_minutes'], 20),
            'recent_assignments' => $this->factor('Recent assignments', $metrics['recent_assignments'], $config['reference_recent_assignments'], 15),
            'consecutive_duties' => $this->factor('Consecutive duties', $metrics['consecutive_duties'], $config['reference_consecutive_duties'], 15),
            'overnight_share' => $this->factor(
                'Overnight assignment share',
                $metrics['recent_assignments'] > 0 ? $metrics['overnight_assignments'] / $metrics['recent_assignments'] : 0,
                1,
                10,
            ),
            'rest_interval' => $this->restFactor($metrics['rest_hours'], $config['reference_rest_hours'], 5),
        ];
        $score = round((float) collect($factors)->sum('points'), 2);
        $level = match (true) {
            $score >= $config['high_score'] => 'high',
            $score >= $config['moderate_score'] => 'moderate',
            default => 'low',
        };

        return [
            'label' => 'Scheduling Workload Risk',
            'level' => $level,
            'score' => $score,
            'factors' => $factors,
            'disclaimer' => 'This is a non-medical scheduling indicator based on configurable system references. It does not diagnose or guarantee prevention of burnout, and hospital HR review is required.',
        ];
    }

    /** @return array<string, int|float|string> */
    private function factor(string $label, int|float $value, int|float $reference, int $maximum): array
    {
        $ratio = $reference > 0 ? min(1, max(0, $value / $reference)) : 0;

        return [
            'label' => $label,
            'value' => round((float) $value, 2),
            'reference' => $reference,
            'points' => round($ratio * $maximum, 2),
            'maximum' => $maximum,
        ];
    }

    /** @return array<string, int|float|string> */
    private function restFactor(int|float $hours, int|float $reference, int $maximum): array
    {
        $ratio = $reference > 0 ? max(0, min(1, ($reference - $hours) / $reference)) : 0;

        return [
            'label' => 'Short rest interval pressure',
            'value' => round((float) $hours, 2),
            'reference' => $reference,
            'points' => round($ratio * $maximum, 2),
            'maximum' => $maximum,
        ];
    }
}
