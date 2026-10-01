<?php

namespace App\Services\Scheduling;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleAssignmentAudit;
use App\Models\ScheduleComplianceReview;
use App\Models\Shift;
use App\Services\ScheduleService;
use App\Support\ScheduleWeek;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The HR Final Validation step: checks what was actually published for a
 * department and date range against the hospital's standing policy, rather
 * than the per-run overrides a manager could have typed into the roster form.
 */
class ScheduleComplianceService
{
    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly StaffingRequirementService $staffingRequirements,
    ) {}

    public function review(Department $department, string $startDate, string $endDate): ScheduleComplianceReview
    {
        $start = Carbon::parse($startDate, config('schedule.timezone'))->startOfDay();
        $end = Carbon::parse($endDate, config('schedule.timezone'))->startOfDay();
        $margin = max(7, (int) config('schedule.max_consecutive_workdays'));

        $employees = Employee::query()
            ->where('department_id', $department->id)
            ->where('employment_status', 'active')
            ->get()
            ->keyBy('id');
        $employeeIds = $employees->keys()->all();

        $assignments = ScheduleAssignment::query()
            ->with('shift')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [$start->copy()->subDays($margin)->toDateString(), $end->copy()->addDays($margin)->toDateString()])
            ->get()
            ->groupBy('employee_id');

        $approvedLeave = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get()
            ->groupBy('employee_id');

        $attendance = AttendanceRecord::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('attendance_date', '>=', $start->toDateString())
            ->whereDate('attendance_date', '<=', $end->toDateString())
            ->get()
            ->groupBy('employee_id');

        $findings = collect();

        foreach ($employees as $employee) {
            $findings = $findings->merge($this->employeeFindings(
                $employee,
                $assignments->get($employee->id, collect()),
                $approvedLeave->get($employee->id, collect()),
                $attendance->get($employee->id, collect()),
                $start,
                $end,
            ));
        }

        $findings = $findings->merge($this->staffingFindings($department, $assignments, $employees, $start, $end));

        $status = match (true) {
            $findings->contains(fn (array $finding) => $finding['severity'] === 'error') => 'failed',
            $findings->isNotEmpty() => 'passed_with_warnings',
            default => 'passed',
        };

        return ScheduleComplianceReview::query()->create([
            'uuid' => (string) Str::uuid(),
            'department_id' => $department->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'status' => $status,
            'findings' => $findings->values()->all(),
        ]);
    }

    /**
     * @param  Collection<int, ScheduleAssignment>  $assignments  This employee's scheduled assignments, with margin
     * @param  Collection<int, LeaveRequest>  $approvedLeave
     * @param  Collection<int, AttendanceRecord>  $attendance  This employee's attendance records within [$start, $end]
     * @return Collection<int, array<string, mixed>>
     */
    private function employeeFindings(Employee $employee, Collection $assignments, Collection $approvedLeave, Collection $attendance, Carbon $start, Carbon $end): Collection
    {
        $findings = collect();
        $inRange = $assignments->filter(fn (ScheduleAssignment $assignment) => $assignment->work_date->betweenIncluded($start, $end));
        $rules = config('schedule.compliance');

        foreach ($approvedLeave as $leave) {
            $overlapping = $inRange->first(fn (ScheduleAssignment $assignment) => $assignment->work_date->betweenIncluded($leave->start_date, $leave->end_date));
            if ($overlapping !== null) {
                $findings->push($this->finding('leave_overlap', 'error', "{$employee->full_name} is scheduled on {$overlapping->work_date->format('M j, Y')} while on approved leave.", $employee->id, $overlapping->work_date->toDateString()));
            }
        }

        $sortedDates = $assignments->pluck('work_date')->map(fn ($date) => Carbon::parse($date)->toDateString())->unique()->sort()->values();
        $dateSet = $sortedDates->flip();

        foreach ($inRange as $assignment) {
            $rest = $this->scheduleService->restConflictsFor($employee, $assignment->shift, $assignment->work_date->toDateString(), $assignment->id);
            if ($rest->isNotEmpty()) {
                $findings->push($this->finding('minimum_rest', 'error', "{$employee->full_name} does not have the configured minimum rest around {$assignment->work_date->format('M j, Y')}.", $employee->id, $assignment->work_date->toDateString()));
            }
        }

        foreach (CarbonPeriod::create(ScheduleWeek::start($start), '1 week', ScheduleWeek::end($end)) as $weekStart) {
            $weekStart = Carbon::instance($weekStart);
            $weekEnd = ScheduleWeek::end($weekStart);
            $weekAssignments = $assignments->filter(fn (ScheduleAssignment $assignment) => $assignment->work_date->betweenIncluded($weekStart, $weekEnd));
            if ($weekAssignments->isEmpty()) {
                continue;
            }

            $distinctDays = $weekAssignments->pluck('work_date')->map->toDateString()->unique()->count();
            if ($distinctDays >= 7 - $rules['days_off_per_week']) {
                $findings->push($this->finding('rest_days', 'error', "{$employee->full_name} did not receive the required weekly rest day(s) for the week of {$weekStart->format('M j, Y')}.", $employee->id, $weekStart->toDateString()));
            }

            $weeklyMinutes = $weekAssignments->sum(fn (ScheduleAssignment $assignment) => $assignment->shift->duration_minutes);
            if ($weeklyMinutes > $rules['max_hours_per_week'] * 60) {
                $findings->push($this->finding('max_hours', 'error', "{$employee->full_name} exceeds {$rules['max_hours_per_week']} hours for the week of {$weekStart->format('M j, Y')} (".round($weeklyMinutes / 60, 1).' hours).', $employee->id, $weekStart->toDateString()));
            }

            $nightShifts = $weekAssignments->filter(fn (ScheduleAssignment $assignment) => $this->isNightShift($assignment->shift))->count();
            if ($nightShifts > $rules['night_shift_limit']) {
                $findings->push($this->finding('night_shift_limit', 'error', "{$employee->full_name} exceeds the night-shift limit for the week of {$weekStart->format('M j, Y')} ({$nightShifts} night shifts).", $employee->id, $weekStart->toDateString()));
            }
        }

        foreach ($inRange as $assignment) {
            $date = $assignment->work_date->toDateString();
            $streak = 1;
            $cursor = Carbon::parse($date)->subDay();
            while ($dateSet->has($cursor->toDateString())) {
                $streak++;
                $cursor->subDay();
            }
            $cursor = Carbon::parse($date)->addDay();
            while ($dateSet->has($cursor->toDateString())) {
                $streak++;
                $cursor->addDay();
            }

            $maxConsecutive = (int) config('schedule.max_consecutive_workdays');
            if ($maxConsecutive > 0 && $streak > $maxConsecutive) {
                $findings->push($this->finding('consecutive_workdays', 'error', "{$employee->full_name} is scheduled {$streak} consecutive days around {$assignment->work_date->format('M j, Y')}, exceeding the {$maxConsecutive}-day limit.", $employee->id, $date));

                break;
            }
        }

        // Mirrors the consecutive-workday walk above, scoped to night shifts:
        // a per-week night count alone would miss a streak that crosses a
        // week boundary, letting a published schedule carry an undetected
        // run of consecutive nights through to being locked.
        $nightDateSet = $assignments
            ->filter(fn (ScheduleAssignment $assignment) => $this->isNightShift($assignment->shift))
            ->pluck('work_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->unique()
            ->flip();

        foreach ($inRange as $assignment) {
            if (! $this->isNightShift($assignment->shift)) {
                continue;
            }

            $date = $assignment->work_date->toDateString();
            $streak = 1;
            $cursor = Carbon::parse($date)->subDay();
            while ($nightDateSet->has($cursor->toDateString())) {
                $streak++;
                $cursor->subDay();
            }
            $cursor = Carbon::parse($date)->addDay();
            while ($nightDateSet->has($cursor->toDateString())) {
                $streak++;
                $cursor->addDay();
            }

            $maxConsecutiveNights = (int) $rules['max_consecutive_nights'];
            // Tier B: worth HR's attention, but — unlike a missed rest day or
            // an uncovered shift — not itself grounds to fail the review.
            if ($maxConsecutiveNights > 0 && $streak > $maxConsecutiveNights) {
                $findings->push($this->finding('consecutive_nights', 'warning', "{$employee->full_name} is scheduled {$streak} consecutive night shifts around {$assignment->work_date->format('M j, Y')}, exceeding the {$maxConsecutiveNights}-night limit.", $employee->id, $date));

                break;
            }
        }

        // Labor Code Art. 91: at least 24 consecutive hours off after every
        // six consecutive workdays, checked against actual elapsed time —
        // see ScheduleService::weeklyRestViolated for why a calendar "day
        // off" between two shifts doesn't by itself guarantee this.
        foreach ($inRange as $assignment) {
            if ($this->weeklyRestViolated($assignment, $assignments)) {
                $findings->push($this->finding('weekly_rest', 'error', "{$employee->full_name} did not receive 24 consecutive hours of rest after six consecutive workdays around {$assignment->work_date->format('M j, Y')}.", $employee->id, $assignment->work_date->toDateString()));

                break;
            }
        }

        $findings = $findings->merge($this->provenanceFindings($employee, $inRange));
        $findings = $findings->merge($this->adherenceFindings($employee, $attendance));

        return $findings->unique(fn (array $finding) => $finding['rule'].'|'.$finding['employee_id'].'|'.$finding['date']);
    }

    /**
     * Actual-vs-planned findings (§7 of the schedule-aware attendance plan) —
     * distinct from provenanceFindings() below, which is about who wrote the
     * roster row, not whether the employee actually worked it as published.
     *
     * @param  Collection<int, AttendanceRecord>  $attendance
     * @return Collection<int, array<string, mixed>>
     */
    private function adherenceFindings(Employee $employee, Collection $attendance): Collection
    {
        $findings = collect();

        $offShift = $attendance->where('binding_source', 'override');
        if ($offShift->isNotEmpty()) {
            $findings->push($this->finding(
                'off_shift_attendance',
                'warning',
                "{$employee->full_name} has {$offShift->count()} attendance record(s) in this period matching no published shift.",
                $employee->id,
                null,
            ));
        }

        $overridden = $attendance->whereNotNull('override_authorised_by');
        if ($overridden->isNotEmpty()) {
            $findings->push($this->finding(
                'manager_overridden_attendance',
                'warning',
                "{$employee->full_name} has {$overridden->count()} manager-authorised unscheduled attendance record(s) in this period.",
                $employee->id,
                null,
            ));
        }

        return $findings;
    }

    /**
     * Conformance findings for the write-boundary invariant (Part B of the
     * schedule-aware attendance plan) — not a labor-rule check like the rest
     * of this method, but this is the one component that already writes a
     * findings report on the read path, so it's where the doc puts them.
     *
     * @param  Collection<int, ScheduleAssignment>  $inRange
     * @return Collection<int, array<string, mixed>>
     */
    private function provenanceFindings(Employee $employee, Collection $inRange): Collection
    {
        $findings = collect();

        $missingProvenance = $inRange->whereNull('created_by');
        if ($missingProvenance->isNotEmpty()) {
            $findings->push($this->finding(
                'missing_provenance',
                'error',
                "{$employee->full_name} has {$missingProvenance->count()} schedule assignment(s) with no recorded author in this period.",
                $employee->id,
                null,
            ));
        }

        $legacy = $inRange->where('created_via', 'legacy');
        if ($legacy->isNotEmpty()) {
            $findings->push($this->finding(
                'legacy_provenance',
                'warning',
                "{$employee->full_name} has {$legacy->count()} schedule assignment(s) from before provenance tracking (legacy) in this period.",
                $employee->id,
                null,
            ));
        }

        $auditedIds = ScheduleAssignmentAudit::query()
            ->whereIn('schedule_assignment_id', $inRange->pluck('id'))
            ->where('action', 'created')
            ->pluck('schedule_assignment_id')
            ->unique();
        $unaudited = $inRange->whereNotIn('id', $auditedIds);
        if ($unaudited->isNotEmpty()) {
            $findings->push($this->finding(
                'unaudited_provenance',
                'warning',
                "{$employee->full_name} has {$unaudited->count()} schedule assignment(s) in this period that predate audit tracking.",
                $employee->id,
                null,
            ));
        }

        return $findings;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function staffingFindings(Department $department, Collection $assignmentsByEmployee, Collection $employees, Carbon $start, Carbon $end): Collection
    {
        $shifts = Shift::query()->where('is_active', true)->orderBy('start_time')->get();
        $requirements = $this->staffingRequirements->forShifts($department, $shifts);
        $findings = collect();

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $date = Carbon::instance($date)->toDateString();

            foreach ($shifts as $shift) {
                $onShift = $assignmentsByEmployee->flatten()->filter(
                    fn (ScheduleAssignment $assignment) => $assignment->work_date->toDateString() === $date && $assignment->shift_id === $shift->id,
                );
                $requirement = $requirements->get($shift->id, ['staff' => 1, 'senior' => 0]);

                if ($onShift->count() < $requirement['staff']) {
                    $findings->push($this->finding('staffing_minimum', 'warning', "{$department->name}'s {$shift->name} shift on ".Carbon::parse($date)->format('M j, Y')." is short: {$onShift->count()}/{$requirement['staff']} staff.", null, $date));
                }
            }
        }

        return $findings;
    }

    /**
     * Mirrors {@see ScheduleService::weeklyRestViolated()}: Labor
     * Code Art. 91 requires at least 24 consecutive hours off after every six
     * consecutive workdays, measured against actual elapsed time rather than
     * calendar dates.
     *
     * @param  Collection<int, ScheduleAssignment>  $assignments  This employee's assignments, excluding $assignment itself is not required — the filter below excludes it by end-time.
     */
    private function weeklyRestViolated(ScheduleAssignment $assignment, Collection $assignments): bool
    {
        $requiredDays = (int) config('schedule.max_consecutive_workdays');
        $requiredMinutes = max(0, (int) config('schedule.weekly_rest_hours')) * 60;
        if ($requiredDays <= 0 || $requiredMinutes <= 0) {
            return false;
        }

        [$candidateStart] = $this->scheduleService->intervalFor($assignment->shift, $assignment->work_date->toDateString());

        $mostRecent = $assignments
            ->reject(fn (ScheduleAssignment $other) => $other->is($assignment))
            ->map(function (ScheduleAssignment $other) {
                [, $end] = $this->scheduleService->intervalFor($other->shift, $other->work_date->toDateString());

                return ['assignment' => $other, 'end' => $end];
            })
            ->filter(fn (array $row) => $row['end']->lessThanOrEqualTo($candidateStart))
            ->sortByDesc(fn (array $row) => $row['end'])
            ->first();

        if ($mostRecent === null) {
            return false;
        }

        $scheduledDates = $assignments->pluck('work_date')->map(fn ($workDate) => Carbon::parse($workDate)->toDateString())->unique()->flip();
        $lastWorkedDate = Carbon::parse($mostRecent['assignment']->work_date)->startOfDay();

        $streak = 1;
        $cursor = $lastWorkedDate->copy()->subDay();
        while ($scheduledDates->has($cursor->toDateString())) {
            $streak++;
            $cursor->subDay();
        }

        if ($streak < $requiredDays) {
            return false;
        }

        return $mostRecent['end']->diffInMinutes($candidateStart) < $requiredMinutes;
    }

    private function isNightShift(Shift $shift): bool
    {
        $hour = (int) Carbon::parse($shift->start_time)->format('G');

        return $shift->crosses_midnight || $hour >= 18 || $hour < 6;
    }

    /** @return array<string, mixed> */
    private function finding(string $rule, string $severity, string $message, ?int $employeeId, ?string $date): array
    {
        return [
            'rule' => $rule,
            'severity' => $severity,
            'message' => $message,
            'employee_id' => $employeeId,
            'date' => $date,
        ];
    }
}
