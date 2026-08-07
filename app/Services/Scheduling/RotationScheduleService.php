<?php

namespace App\Services\Scheduling;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PreferredDayOff;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\Shift;
use App\Models\User;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RotationScheduleService
{
    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly StaffingRequirementService $staffingRequirements,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function plan(array $data): array
    {
        $employees = Employee::query()
            ->with(['department', 'position'])
            ->whereKey($data['employee_ids'])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
        $shifts = Shift::query()
            ->whereKey($data['shift_ids'])
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get()
            ->values();

        if ($shifts->count() !== count($data['shift_ids'])) {
            throw ValidationException::withMessages(['shift_ids' => 'Every rotation shift must be active.']);
        }

        // Coverage is resolved before anyone is placed, so the rotation can aim at
        // what each shift needs instead of splitting the team evenly and reporting
        // the shortfall afterwards.
        $department = Department::query()->with('shiftRequirements')->findOrFail($data['department_id']);
        $requirements = $this->staffingRequirements->forShifts($department, $shifts);

        if (isset($data['minimum_staff_per_shift']) || isset($data['minimum_senior_per_shift'])) {
            $requirements = $requirements->map(fn (array $requirement): array => [
                'staff' => (int) ($data['minimum_staff_per_shift'] ?? $requirement['staff']),
                'senior' => (int) ($data['minimum_senior_per_shift'] ?? $requirement['senior']),
                'source' => 'roster form',
            ]);
        }

        $start = Carbon::parse($data['start_date'], config('schedule.timezone'))->startOfDay();
        $end = Carbon::parse($data['end_date'], config('schedule.timezone'))->startOfDay();
        $weeks = collect(CarbonPeriod::create($start, $end))
            ->map(fn ($date) => Carbon::instance($date)->timezone(config('schedule.timezone'))->startOfDay())
            ->values()
            ->chunk(7)
            ->values();
        $employeeIds = $employees->pluck('id')->all();
        // Extra margin beyond the week boundary so the consecutive-workday
        // check can see a streak that started before this range.
        $streakMargin = max(1, (int) config('schedule.max_consecutive_workdays'));
        $existingAssignments = ScheduleAssignment::query()
            ->with('shift')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [
                $start->copy()->startOfWeek()->subDay()->subDays($streakMargin)->toDateString(),
                $end->copy()->endOfWeek()->addDay()->addDays($streakMargin)->toDateString(),
            ])
            ->get()
            ->groupBy('employee_id');
        $existingDayOffs = ScheduleDayOff::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('employee_id');
        $leaves = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get()
            ->groupBy('employee_id');
        $preferredDayOffs = PreferredDayOff::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereBetween('preferred_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('employee_id');
        $previousShiftIds = ScheduleAssignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('shift_id', $shifts->pluck('id'))
            ->where('status', 'scheduled')
            ->whereDate('work_date', '<', $start->toDateString())
            ->latest('work_date')
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $items) => (int) $items->first()->shift_id);
        $scheduleMethod = $data['schedule_method'] ?? 'rotation';
        $seniorRankThreshold = (int) ($data['senior_rank_threshold'] ?? ScheduleService::DEFAULT_SENIOR_RANK_THRESHOLD);
        $rotationMatrix = $this->rotationMatrix(
            $employees,
            $weeks,
            $shifts,
            $previousShiftIds,
            $scheduleMethod,
            $requirements,
            $seniorRankThreshold,
        );
        $readyAssignments = collect();
        $readyDayOffs = collect();
        $skipped = collect();
        $rows = collect();

        foreach ($employees->values() as $employeeIndex => $employee) {
            $employeeWeeks = collect();
            $employeeAssignments = $existingAssignments->get($employee->id, collect());
            $employeeDayOffs = $existingDayOffs->get($employee->id, collect());
            $employeeLeaves = $leaves->get($employee->id, collect());
            $employeePreferredDates = $preferredDayOffs->get($employee->id, collect())->map(fn (PreferredDayOff $preference) => $preference->preferred_date);
            $dayOffOffset = $employeeIndex % 7;

            foreach ($weeks as $weekIndex => $weekDates) {
                $shift = $rotationMatrix[$weekIndex][$employee->id];
                $existingWeekDayOffs = $employeeDayOffs->filter(fn (ScheduleDayOff $dayOff) => $weekDates->contains(
                    fn (Carbon $date) => $date->toDateString() === $dayOff->work_date->toDateString(),
                ));
                $dayOffDates = $existingWeekDayOffs
                    ->map(fn (ScheduleDayOff $dayOff) => $dayOff->work_date->copy())
                    ->values();
                $requiredDaysOff = min((int) ($data['days_off_per_week'] ?? 1), max(0, $weekDates->count() - 1));
                $newDayOffs = $this->chooseDayOffs(
                    $weekDates,
                    $dayOffOffset,
                    $shift,
                    $employeeAssignments,
                    $employeeLeaves,
                    max(0, $requiredDaysOff - $dayOffDates->count()),
                    $dayOffDates,
                    $data['holiday_dates'] ?? [],
                    $employeePreferredDates,
                    $employee->preferred_weekly_off_day,
                );
                foreach ($newDayOffs as $dayOffDate) {
                    $dayOffDates->push($dayOffDate);
                    $readyDayOffs->push(['employee' => $employee, 'date' => $dayOffDate]);
                }
                if ($dayOffDates->count() < $requiredDaysOff) {
                    $skipped->push([
                        'employee' => $employee->full_name,
                        'date' => $weekDates->first()->toDateString(),
                        'reason' => 'No safe day-off date is available in this week',
                    ]);
                }
                if ($dayOffDates->isNotEmpty()) {
                    $dayOffOffset = (int) $weekDates->search(
                        fn (Carbon $date) => $date->toDateString() === $dayOffDates->first()->toDateString(),
                    );
                }

                $weekReady = 0;
                $weekSkipped = 0;
                $dailySchedule = collect();
                foreach ($weekDates as $date) {
                    if ($dayOffDates->contains(fn (Carbon $dayOff) => $dayOff->toDateString() === $date->toDateString())) {
                        $dailySchedule->push([
                            'date' => $date->toDateString(),
                            'status' => 'day_off',
                            'shift' => null,
                            'shift_time' => null,
                            'color' => null,
                            'reason' => 'Protected day off',
                        ]);

                        continue;
                    }

                    $reason = $this->assignmentBlockReason(
                        $employee,
                        $shift,
                        $date,
                        $employeeAssignments,
                        $employeeDayOffs,
                        $employeeLeaves,
                        $data,
                    );
                    if ($reason !== null) {
                        $skipped->push([
                            'employee' => $employee->full_name,
                            'date' => $date->toDateString(),
                            'shift' => $shift->name,
                            'reason' => $reason,
                        ]);
                        $dailySchedule->push([
                            'date' => $date->toDateString(),
                            'status' => 'skipped',
                            'shift' => $shift->name,
                            'shift_time' => $shift->formatted_time,
                            'color' => $shift->color,
                            'reason' => $reason,
                        ]);
                        $weekSkipped++;

                        continue;
                    }

                    $readyAssignments->push(['employee' => $employee, 'shift' => $shift, 'date' => $date]);
                    $dailySchedule->push([
                        'date' => $date->toDateString(),
                        'status' => 'scheduled',
                        'shift' => $shift->name,
                        'shift_time' => $shift->formatted_time,
                        'color' => $shift->color,
                        'reason' => 'Ready to publish',
                    ]);
                    $plannedAssignment = new ScheduleAssignment([
                        'employee_id' => $employee->id,
                        'shift_id' => $shift->id,
                        'work_date' => $date->toDateString(),
                        'status' => 'scheduled',
                    ]);
                    $plannedAssignment->setRelation('shift', $shift);
                    $employeeAssignments->push($plannedAssignment);
                    $weekReady++;
                }

                $employeeWeeks->push([
                    'start_date' => $weekDates->first()->toDateString(),
                    'end_date' => $weekDates->last()->toDateString(),
                    'shift_id' => $shift->id,
                    'shift' => $shift->name,
                    'shift_time' => $shift->formatted_time,
                    'color' => $shift->color,
                    'day_off' => $dayOffDates->first()?->toDateString(),
                    'day_offs' => $dayOffDates->map->toDateString()->values(),
                    'assignments' => $weekReady,
                    'skipped' => $weekSkipped,
                    'days' => $dailySchedule,
                ]);
            }

            $rows->push([
                'employee_id' => $employee->id,
                'employee' => $employee->full_name,
                'employee_number' => $employee->employee_number,
                'department' => $employee->department?->name,
                'position' => $employee->position?->title,
                'weeks' => $employeeWeeks,
            ]);
        }

        $seniorRank = (int) ($data['senior_rank_threshold'] ?? ScheduleService::DEFAULT_SENIOR_RANK_THRESHOLD);

        $staffingGaps = collect(CarbonPeriod::create($start, $end))
            ->flatMap(function ($date) use ($shifts, $readyAssignments, $requirements, $seniorRank) {
                $dateString = Carbon::instance($date)->toDateString();

                return $shifts->flatMap(function (Shift $shift) use ($readyAssignments, $requirements, $seniorRank, $dateString) {
                    $onShift = $readyAssignments
                        ->filter(fn (array $item) => $item['date']->toDateString() === $dateString && $item['shift']->id === $shift->id);
                    $requirement = $requirements->get($shift->id);
                    $minimumStaff = $requirement['staff'];
                    $minimumSenior = $requirement['senior'];
                    $gaps = collect();

                    if ($onShift->count() < $minimumStaff) {
                        $gaps->push([
                            'date' => $dateString,
                            'shift' => $shift->name,
                            'label' => 'staff',
                            'available' => $onShift->count(),
                            'required' => $minimumStaff,
                            'suggestion' => 'Required by the '.$requirement['source'].'. Add eligible staff or revise the unit standard.',
                        ]);
                    }

                    // A rotation can spread the senior staff thin, leaving a shift
                    // with nobody able to take charge even when the count is met.
                    if ($minimumSenior > 0) {
                        $seniorsOnShift = $onShift
                            ->filter(fn (array $item) => (int) ($item['employee']->position?->seniority_rank ?? 1) >= $seniorRank)
                            ->count();

                        if ($seniorsOnShift < $minimumSenior) {
                            $gaps->push([
                                'date' => $dateString,
                                'shift' => $shift->name,
                                'label' => 'senior staff (rank '.$seniorRank.'+)',
                                'available' => $seniorsOnShift,
                                'required' => $minimumSenior,
                                'suggestion' => 'Add a senior or charge-level employee to this shift so it is not covered by entry-level staff alone.',
                            ]);
                        }
                    }

                    return $gaps;
                });
            })
            ->values();

        $patternLabel = $scheduleMethod === 'custom' ? 'Custom AI mix' : 'Balanced rotation';

        return [
            'rows' => $rows,
            'ready_assignments' => $readyAssignments,
            'ready_day_offs' => $readyDayOffs,
            'skipped' => $skipped,
            'assignment_count' => $readyAssignments->count(),
            'day_off_count' => $readyDayOffs->count(),
            'skipped_count' => $skipped->count(),
            'staffing_gaps' => $staffingGaps,
            'notice' => "{$patternLabel} fills each shift towards {$department->name}'s recorded coverage standard, then applies the selected days-off, maximum-hours, night-shift, overtime, leave, and rest rules before HR approval.",
            'coverage_standard' => $this->staffingRequirements->derivationSummary($department),
        ];
    }


    /**
     * Decide which shift each employee works in each week.
     *
     * Shifts are filled towards their own requirement rather than given an equal
     * share of the team: a unit needing four on mornings and two on nights should
     * not receive three and three. Seniors are steered towards shifts that still
     * have nobody able to take charge.
     *
     * @param  Collection<int, array{staff: int, senior: int, source: string}>  $requirements
     * @return array<int, array<int, Shift>>
     */
    private function rotationMatrix(
        Collection $employees,
        Collection $weeks,
        Collection $shifts,
        Collection $previousShiftIds,
        string $scheduleMethod,
        Collection $requirements,
        int $seniorRankThreshold,
    ): array {
        $matrix = [];
        $lastShiftIds = $previousShiftIds;

        foreach ($weeks as $weekIndex => $weekDates) {
            $counts = $shifts->mapWithKeys(fn (Shift $shift) => [$shift->id => 0]);
            $seniorCounts = $shifts->mapWithKeys(fn (Shift $shift) => [$shift->id => 0]);

            // Seniors are placed first so the charge cover lands where it is needed
            // before the remaining places are filled.
            $ordered = $employees->values()
                ->sortByDesc(fn (Employee $employee) => (int) ($employee->position?->seniority_rank ?? 1))
                ->values();

            foreach ($ordered as $employee) {
                $employeeIndex = $employees->values()->search(fn (Employee $candidate) => $candidate->id === $employee->id);
                $preferredIndex = ($employeeIndex + ($scheduleMethod === 'rotation' ? $weekIndex : 0)) % $shifts->count();
                $lastShiftId = $lastShiftIds->get($employee->id);
                $isSenior = (int) ($employee->position?->seniority_rank ?? 1) >= $seniorRankThreshold;

                $shift = $shifts->sortBy(function (Shift $candidate, int $index) use (
                    $counts, $seniorCounts, $requirements, $lastShiftId, $preferredIndex, $shifts, $scheduleMethod, $isSenior
                ) {
                    $requirement = $requirements->get($candidate->id, ['staff' => 1, 'senior' => 0]);

                    // Shifts still short of their requirement sort first; once a
                    // shift is satisfied its surplus keeps pushing it down.
                    $shortfall = max(0, $requirement['staff'] - $counts[$candidate->id]);
                    $needPenalty = $shortfall > 0 ? -($shortfall * 1000) : $counts[$candidate->id] * 100;

                    $seniorShortfall = max(0, $requirement['senior'] - $seniorCounts[$candidate->id]);
                    $seniorPenalty = $isSenior && $seniorShortfall > 0 ? -($seniorShortfall * 5000) : 0;

                    $repeatPenalty = $scheduleMethod === 'rotation' && $candidate->id === $lastShiftId ? 25 : 0;
                    $preferenceDistance = ($index - $preferredIndex + $shifts->count()) % $shifts->count();

                    return $seniorPenalty + $needPenalty + $repeatPenalty + $preferenceDistance;
                })->first();

                $matrix[$weekIndex][$employee->id] = $shift;
                $counts->put($shift->id, $counts->get($shift->id) + 1);

                if ($isSenior) {
                    $seniorCounts->put($shift->id, $seniorCounts->get($shift->id) + 1);
                }

                $lastShiftIds->put($employee->id, $shift->id);
            }
        }

        return $matrix;
    }

    /**
     * Which dates in this week become the employee's day(s) off.
     *
     * Order of preference: a rest violation still wins outright (it is a real
     * constraint, not a courtesy), then an HR-approved preferred-day-off
     * request, then the employee's standing weekly rest-day preference, and
     * only then the rotation's own even-spread offset. This is what makes the
     * roster actually honor "prefer employee day-off requests when staffing
     * allows" instead of only offering a form nobody reads.
     *
     * @param  Collection<int, Carbon>  $preferredDates  Approved PreferredDayOff dates for this employee
     */
    private function chooseDayOffs(
        Collection $dates,
        int $preferredOffset,
        Shift $shift,
        Collection $assignments,
        Collection $leaves,
        int $count,
        Collection $alreadySelected,
        array $holidayDates,
        Collection $preferredDates = new Collection(),
        ?int $preferredWeeklyOffDay = null,
    ): Collection {
        if ($count === 0) {
            return collect();
        }

        $available = $dates->filter(fn (Carbon $date) => ! $this->hasLeave($leaves, $date)
            && ! in_array($date->toDateString(), $holidayDates, true)
            && ! $assignments->contains(fn (ScheduleAssignment $assignment) => $assignment->work_date->toDateString() === $date->toDateString()))
            ->reject(fn (Carbon $date) => $alreadySelected->contains(
                fn (Carbon $selected) => $selected->toDateString() === $date->toDateString(),
            ))
            ->values();
        if ($available->isEmpty()) {
            return collect();
        }

        $restRecoveryDate = $available->first(fn (Carbon $date) => $this->hasRestViolation($shift, $date, $assignments));
        $ordered = $available->sortBy(function (Carbon $date) use ($dates, $preferredOffset, $restRecoveryDate, $preferredDates, $preferredWeeklyOffDay) {
            $index = (int) $dates->search(fn (Carbon $candidate) => $candidate->toDateString() === $date->toDateString());
            $offsetDistance = ($index - $preferredOffset + $dates->count()) % $dates->count();

            if ($restRecoveryDate?->toDateString() === $date->toDateString()) {
                return -1000;
            }

            if ($preferredDates->contains(fn (Carbon $preferred) => $preferred->toDateString() === $date->toDateString())) {
                return $offsetDistance - 500;
            }

            if ($preferredWeeklyOffDay !== null && $date->dayOfWeekIso === $preferredWeeklyOffDay) {
                return $offsetDistance - 100;
            }

            return $offsetDistance;
        })->values();

        return $ordered->take($count)->map(fn (Carbon $date) => $date->copy())->values();
    }

    private function assignmentBlockReason(
        Employee $employee,
        Shift $shift,
        Carbon $date,
        Collection $assignments,
        Collection $dayOffs,
        Collection $leaves,
        array $rules = [],
    ): ?string {
        if ($employee->employment_status !== 'active') {
            return 'Inactive employee';
        }
        if (in_array($date->toDateString(), $rules['holiday_dates'] ?? [], true)) {
            return 'Holiday or closure date';
        }
        if ($this->hasLeave($leaves, $date)) {
            return 'Approved leave';
        }
        if ($dayOffs->contains(fn (ScheduleDayOff $dayOff) => $dayOff->work_date->toDateString() === $date->toDateString())) {
            return 'Existing day off';
        }

        [$candidateStart, $candidateEnd] = $this->scheduleService->intervalFor($shift, $date->toDateString());
        $conflict = $assignments->contains(function (ScheduleAssignment $assignment) use ($candidateStart, $candidateEnd) {
            [$existingStart, $existingEnd] = $this->scheduleService->intervalFor($assignment->shift, $assignment->work_date->toDateString());

            return $candidateStart->lessThan($existingEnd) && $candidateEnd->greaterThan($existingStart);
        });

        if ($conflict) {
            return 'Overlapping schedule';
        }
        if ($this->hasRestViolation($shift, $date, $assignments)) {
            return 'Minimum rest period not met';
        }
        if ($this->consecutiveWorkdaysExceeded($date, $assignments)) {
            return 'Maximum consecutive workdays exceeded';
        }

        $weekStart = $date->copy()->startOfWeek();
        $weekEnd = $date->copy()->endOfWeek();
        $weeklyAssignments = $assignments->filter(fn (ScheduleAssignment $assignment) => $assignment->work_date->betweenIncluded($weekStart, $weekEnd));
        $overtimeAllowed = (bool) ($rules['overtime_allowed'] ?? false);
        $maximumHours = (int) ($rules['max_hours_per_week'] ?? 168);
        $scheduledMinutes = $weeklyAssignments->sum(fn (ScheduleAssignment $assignment) => $assignment->shift->duration_minutes);
        if (! $overtimeAllowed && $scheduledMinutes + $shift->duration_minutes > $maximumHours * 60) {
            return 'Maximum weekly hours exceeded';
        }

        $nightShiftLimit = (int) ($rules['night_shift_limit'] ?? 6);
        if ($this->isNightShift($shift)
            && $weeklyAssignments->filter(fn (ScheduleAssignment $assignment) => $this->isNightShift($assignment->shift))->count() >= $nightShiftLimit) {
            return 'Night shift limit exceeded';
        }

        return null;
    }

    private function isNightShift(Shift $shift): bool
    {
        $hour = (int) Carbon::parse($shift->start_time)->format('G');

        return $shift->crosses_midnight || $hour >= 18 || $hour < 6;
    }

    /** @param  Collection<int, ScheduleAssignment>  $assignments */
    private function consecutiveWorkdaysExceeded(Carbon $date, Collection $assignments): bool
    {
        $maxConsecutive = (int) config('schedule.max_consecutive_workdays');
        if ($maxConsecutive <= 0) {
            return false;
        }

        $scheduledDates = $assignments->pluck('work_date')->map(fn ($workDate) => Carbon::parse($workDate)->toDateString())->unique()->flip();

        $streak = 1;
        $cursor = $date->copy()->subDay();
        while ($scheduledDates->has($cursor->toDateString())) {
            $streak++;
            $cursor->subDay();
        }
        $cursor = $date->copy()->addDay();
        while ($scheduledDates->has($cursor->toDateString())) {
            $streak++;
            $cursor->addDay();
        }

        return $streak > $maxConsecutive;
    }

    private function hasRestViolation(Shift $shift, Carbon $date, Collection $assignments): bool
    {
        $minimumMinutes = max(0, (int) config('schedule.minimum_rest_hours')) * 60;
        if ($minimumMinutes === 0) {
            return false;
        }

        [$candidateStart, $candidateEnd] = $this->scheduleService->intervalFor($shift, $date->toDateString());

        return $assignments->contains(function (ScheduleAssignment $assignment) use ($candidateStart, $candidateEnd, $minimumMinutes) {
            [$existingStart, $existingEnd] = $this->scheduleService->intervalFor($assignment->shift, $assignment->work_date->toDateString());
            if ($candidateStart->greaterThanOrEqualTo($existingEnd)) {
                return $existingEnd->diffInMinutes($candidateStart) < $minimumMinutes;
            }
            if ($existingStart->greaterThanOrEqualTo($candidateEnd)) {
                return $candidateEnd->diffInMinutes($existingStart) < $minimumMinutes;
            }

            return false;
        });
    }

    private function hasLeave(Collection $leaves, Carbon $date): bool
    {
        return $leaves->contains(fn (LeaveRequest $leave) => $date->toDateString() >= $leave->start_date->toDateString()
            && $date->toDateString() <= $leave->end_date->toDateString());
    }
}
