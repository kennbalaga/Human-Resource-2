<?php

namespace App\Services\Scheduling;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Services\Burnout\BurnoutRiskService;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CandidateScoringService
{
    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly BurnoutRiskService $burnoutRisk,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $eligibleCandidates
     * @return array<int, array<string, mixed>>
     */
    public function rank(array $eligibleCandidates, Shift $shift, string $workDate): array
    {
        if ($eligibleCandidates === []) {
            return [];
        }

        $employees = Employee::query()
            ->whereIn('id', collect($eligibleCandidates)->pluck('employee_id'))
            ->get()
            ->keyBy('id');
        $metrics = collect($eligibleCandidates)->mapWithKeys(function (array $candidate) use ($employees, $shift, $workDate): array {
            $employee = $employees->get($candidate['employee_id']);

            return [$candidate['employee_id'] => $this->metricsFor($employee, $shift, $workDate)];
        });
        $weights = config('ai_workforce_scheduling.weights');
        // One read for the whole pool rather than one per candidate.
        $burnout = $this->burnoutRisk->current($metrics->keys()->all());

        return collect($eligibleCandidates)
            ->map(function (array $candidate) use ($metrics, $weights, $burnout): array {
                $candidateMetrics = $metrics->get($candidate['employee_id']);
                $assessment = $burnout->get($candidate['employee_id']);
                $presented = $assessment !== null ? $this->burnoutRisk->present($assessment) : null;
                $candidateMetrics['burnout_score'] = $presented['score'] ?? null;
                $candidateMetrics['burnout_level'] = $presented['level'] ?? null;
                $breakdown = [
                    'eligibility' => [
                        'label' => 'All available hard constraints passed',
                        'value' => true,
                        'points' => (float) $weights['eligibility'],
                        'maximum' => (float) $weights['eligibility'],
                    ],
                    'burnout_risk' => $this->burnoutBreakdown($presented, (float) ($weights['burnout_risk'] ?? 0)),
                    'weekly_workload' => $this->lowerIsBetterBreakdown('Weekly workload', 'minutes', $candidateMetrics['weekly_workload_minutes'], $metrics->pluck('weekly_workload_minutes'), $weights['weekly_workload']),
                    'overtime' => $this->lowerIsBetterBreakdown('Approved overtime', 'minutes', $candidateMetrics['overtime_minutes'], $metrics->pluck('overtime_minutes'), $weights['overtime']),
                    'recent_assignments' => $this->lowerIsBetterBreakdown('Recent assignments', 'assignments', $candidateMetrics['recent_assignments'], $metrics->pluck('recent_assignments'), $weights['recent_assignments']),
                    'overnight_assignments' => $this->lowerIsBetterBreakdown('Recent overnight assignments', 'assignments', $candidateMetrics['overnight_assignments'], $metrics->pluck('overnight_assignments'), $weights['overnight_assignments']),
                    'consecutive_duties' => $this->lowerIsBetterBreakdown('Consecutive scheduled duties', 'days', $candidateMetrics['consecutive_duties'], $metrics->pluck('consecutive_duties'), $weights['consecutive_duties']),
                    'shift_preference' => [
                        'label' => 'Matches declared shift preference',
                        'value' => $candidateMetrics['shift_preference_match'],
                        'points' => $candidateMetrics['shift_preference_match'] ? (float) $weights['shift_preference'] : 0.0,
                        'maximum' => (float) $weights['shift_preference'],
                    ],
                    'rest_time' => $this->higherIsBetterBreakdown('Nearest rest interval', 'hours', $candidateMetrics['rest_hours'], $metrics->pluck('rest_hours'), $weights['rest_time']),
                ];
                $score = round((float) collect($breakdown)->sum('points'), 2);

                return $candidate + [
                    'score' => $score,
                    'score_breakdown' => $breakdown,
                    'metrics' => $candidateMetrics,
                    'recommendation_reasons' => $this->reasons($breakdown),
                    'burnout_risk' => $presented === null ? null : [
                        'level' => $presented['level'],
                        'score' => $presented['score'],
                        'trend' => $presented['trend'],
                        'drivers' => collect($presented['drivers'])->pluck('summary')->all(),
                    ],
                    'burnout_protected' => (bool) ($presented['protected'] ?? false),
                ];
            })
            // Anyone at high burnout risk is ranked after everyone who is not,
            // whatever their points, so they are only ever the recommendation
            // when nobody else is eligible. They stay on the list: the manager
            // can still choose them, with the risk shown beside the name.
            ->sortBy([
                ['burnout_protected', 'asc'],
                ['score', 'desc'],
                ['employee_id', 'asc'],
            ])
            ->values()
            ->all();
    }

    /** @return array<string, int|float> */
    public function metricsFor(Employee $employee, Shift $candidateShift, string $workDate): array
    {
        $targetDate = Carbon::parse($workDate, config('schedule.timezone'))->startOfDay();
        $weekStart = $targetDate->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $targetDate->copy()->endOfWeek(Carbon::SUNDAY);
        $historyStart = $targetDate->copy()->subDays(config('ai_workforce_scheduling.history_days'));

        $weekAssignments = ScheduleAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get();
        $attendance = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->where('approval_status', 'approved')
            ->whereBetween('attendance_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get();
        $recentAssignments = ScheduleAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [$historyStart->toDateString(), $targetDate->copy()->subDay()->toDateString()])
            ->get();
        $scheduledMinutes = (int) $weekAssignments->sum(fn (ScheduleAssignment $assignment) => $assignment->shift->duration_minutes);
        $workedMinutes = (int) $attendance->sum('worked_minutes');

        return [
            'scheduled_weekly_minutes' => $scheduledMinutes,
            'worked_weekly_minutes' => $workedMinutes,
            'weekly_workload_minutes' => max($scheduledMinutes, $workedMinutes),
            'overtime_minutes' => (int) $attendance->sum('overtime_minutes'),
            'recent_assignments' => $recentAssignments->count(),
            'overnight_assignments' => $recentAssignments->filter(fn (ScheduleAssignment $assignment) => $assignment->shift->crosses_midnight)->count(),
            'consecutive_duties' => $this->consecutiveDuties($employee, $targetDate),
            'shift_preference_match' => $employee->preferred_shift_id !== null && $employee->preferred_shift_id === $candidateShift->id,
            'rest_hours' => $this->nearestRestHours($employee, $candidateShift, $workDate),
        ];
    }

    private function consecutiveDuties(Employee $employee, Carbon $targetDate): int
    {
        $dates = ScheduleAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [$targetDate->copy()->subDays(31)->toDateString(), $targetDate->copy()->subDay()->toDateString()])
            ->pluck('work_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->flip();
        $consecutive = 0;
        $date = $targetDate->copy()->subDay();

        while ($dates->has($date->toDateString())) {
            $consecutive++;
            $date->subDay();
        }

        return $consecutive;
    }

    private function nearestRestHours(Employee $employee, Shift $candidateShift, string $workDate): float
    {
        [$candidateStart, $candidateEnd] = $this->scheduleService->intervalFor($candidateShift, $workDate);
        $assignments = ScheduleAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [
                $candidateStart->copy()->subDays(7)->toDateString(),
                $candidateEnd->copy()->addDays(7)->toDateString(),
            ])
            ->get();
        $gaps = collect();

        foreach ($assignments as $assignment) {
            [$existingStart, $existingEnd] = $this->scheduleService->intervalFor($assignment->shift, $assignment->work_date->toDateString());
            if ($existingEnd->lessThanOrEqualTo($candidateStart)) {
                $gaps->push($existingEnd->diffInMinutes($candidateStart) / 60);
            } elseif ($existingStart->greaterThanOrEqualTo($candidateEnd)) {
                $gaps->push($candidateEnd->diffInMinutes($existingStart) / 60);
            }
        }

        return round((float) min(72, $gaps->min() ?? 72), 2);
    }

    /**
     * Scored against the fixed 0-100 scale rather than against the pool: two
     * candidates at 4 and 8 are both fine, and a pool-relative scale would
     * hand one of them nothing for a difference that means nothing.
     *
     * No assessment means no evidence either way, so it earns half, never the
     * full points a known low risk would.
     *
     * @param  array<string, mixed>|null  $assessment
     * @return array<string, mixed>
     */
    private function burnoutBreakdown(?array $assessment, float $weight): array
    {
        $quality = $assessment === null ? .5 : 1 - min(100, max(0, (float) $assessment['score'])) / 100;

        return [
            'label' => $assessment === null ? 'Burnout risk (not yet assessed)' : 'Burnout risk ('.$assessment['level'].')',
            'value' => $assessment['score'] ?? null,
            'unit' => 'points',
            'points' => round($quality * $weight, 2),
            'maximum' => $weight,
        ];
    }

    /** @param Collection<int, int|float> $population @return array<string, mixed> */
    private function lowerIsBetterBreakdown(string $label, string $unit, int|float $value, Collection $population, int|float $weight): array
    {
        $quality = $this->normalizedQuality($value, $population, lowerIsBetter: true);

        return ['label' => $label, 'value' => $value, 'unit' => $unit, 'points' => round($quality * $weight, 2), 'maximum' => (float) $weight];
    }

    /** @param Collection<int, int|float> $population @return array<string, mixed> */
    private function higherIsBetterBreakdown(string $label, string $unit, int|float $value, Collection $population, int|float $weight): array
    {
        $quality = $this->normalizedQuality($value, $population, lowerIsBetter: false);

        return ['label' => $label, 'value' => $value, 'unit' => $unit, 'points' => round($quality * $weight, 2), 'maximum' => (float) $weight];
    }

    /** @param Collection<int, int|float> $population */
    private function normalizedQuality(int|float $value, Collection $population, bool $lowerIsBetter): float
    {
        $minimum = (float) $population->min();
        $maximum = (float) $population->max();
        if ($maximum === $minimum) {
            return .5;
        }

        $normalized = ((float) $value - $minimum) / ($maximum - $minimum);

        return $lowerIsBetter ? 1 - $normalized : $normalized;
    }

    /** @param array<string, array<string, mixed>> $breakdown @return array<int, string> */
    private function reasons(array $breakdown): array
    {
        return collect($breakdown)
            ->sortByDesc('points')
            ->take(3)
            ->map(fn (array $factor) => $factor['label'].' contributed '.number_format((float) $factor['points'], 1).' of '.number_format((float) $factor['maximum'], 1).' points.')
            ->values()
            ->all();
    }
}
