<?php

namespace App\Services\Scheduling;

use App\Models\Employee;
use App\Models\LeaveRequest;
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
    public function __construct(private readonly ScheduleService $scheduleService) {}

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

        $start = Carbon::parse($data['start_date'], config('schedule.timezone'))->startOfDay();
        $end = Carbon::parse($data['end_date'], config('schedule.timezone'))->startOfDay();
        $weeks = collect(CarbonPeriod::create($start, $end))
            ->map(fn ($date) => Carbon::instance($date)->timezone(config('schedule.timezone'))->startOfDay())
            ->values()
            ->chunk(7)
            ->values();
        $employeeIds = $employees->pluck('id')->all();
        $existingAssignments = ScheduleAssignment::query()
            ->with('shift')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [
                $start->copy()->startOfWeek()->subDay()->toDateString(),
                $end->copy()->endOfWeek()->addDay()->toDateString(),
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
        $rotationMatrix = $this->rotationMatrix($employees, $weeks, $shifts, $previousShiftIds, $scheduleMethod);
        $readyAssignments = collect();
        $readyDayOffs = collect();
        $skipped = collect();
        $rows = collect();

        foreach ($employees->values() as $employeeIndex => $employee) {
            $employeeWeeks = collect();
            $employeeAssignments = $existingAssignments->get($employee->id, collect());
            $employeeDayOffs = $existingDayOffs->get($employee->id, collect());
            $employeeLeaves = $leaves->get($employee->id, collect());
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

        $minimumStaff = (int) ($data['minimum_staff_per_shift'] ?? 1);
        $staffingGaps = collect(CarbonPeriod::create($start, $end))
            ->flatMap(function ($date) use ($shifts, $readyAssignments, $minimumStaff) {
                $dateString = Carbon::instance($date)->toDateString();

                return $shifts->map(function (Shift $shift) use ($readyAssignments, $minimumStaff, $dateString) {
                    $available = $readyAssignments
                        ->filter(fn (array $item) => $item['date']->toDateString() === $dateString && $item['shift']->id === $shift->id)
                        ->count();

                    return $available < $minimumStaff ? [
                        'date' => $dateString,
                        'shift' => $shift->name,
                        'available' => $available,
                        'required' => $minimumStaff,
                        'suggestion' => 'Add eligible staff, choose fewer shifts, or lower the minimum staffing rule.',
                    ] : null;
                })->filter();
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
            'notice' => "{$patternLabel} applies the selected days-off, maximum-hours, night-shift, overtime, leave, and rest rules before HR approval.",
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data, User $creator): array
    {
        return DB::transaction(function () use ($data, $creator) {
            Employee::query()->whereKey($data['employee_ids'])->lockForUpdate()->get();
            $plan = $this->plan($data);
            $assignments = $plan['ready_assignments']->map(fn (array $item) => ScheduleAssignment::query()->create([
                'employee_id' => $item['employee']->id,
                'shift_id' => $item['shift']->id,
                'work_date' => $item['date']->toDateString(),
                'status' => 'scheduled',
                'notes' => $data['notes'] ?? 'Generated by AI Scheduling Assistant rotation.',
                'created_by' => $creator->id,
            ]));
            $dayOffs = $plan['ready_day_offs']->map(fn (array $item) => ScheduleDayOff::query()->create([
                'employee_id' => $item['employee']->id,
                'work_date' => $item['date']->toDateString(),
                'source' => 'ai_rotation',
                'notes' => $data['notes'] ?? 'Generated weekly day off.',
                'created_by' => $creator->id,
            ]));

            return $plan + ['assignments' => $assignments, 'day_offs' => $dayOffs];
        });
    }

    private function rotationMatrix(
        Collection $employees,
        Collection $weeks,
        Collection $shifts,
        Collection $previousShiftIds,
        string $scheduleMethod,
    ): array {
        $matrix = [];
        $lastShiftIds = $previousShiftIds;

        foreach ($weeks as $weekIndex => $weekDates) {
            $counts = $shifts->mapWithKeys(fn (Shift $shift) => [$shift->id => 0]);
            foreach ($employees->values() as $employeeIndex => $employee) {
                $preferredIndex = ($employeeIndex + ($scheduleMethod === 'rotation' ? $weekIndex : 0)) % $shifts->count();
                $lastShiftId = $lastShiftIds->get($employee->id);
                $shift = $shifts->sortBy(function (Shift $candidate, int $index) use ($counts, $lastShiftId, $preferredIndex, $shifts, $scheduleMethod) {
                    $balancePenalty = $counts[$candidate->id] * 100;
                    $repeatPenalty = $scheduleMethod === 'rotation' && $candidate->id === $lastShiftId ? 25 : 0;
                    $preferenceDistance = ($index - $preferredIndex + $shifts->count()) % $shifts->count();

                    return $balancePenalty + $repeatPenalty + $preferenceDistance;
                })->first();
                $matrix[$weekIndex][$employee->id] = $shift;
                $counts->put($shift->id, $counts->get($shift->id) + 1);
                $lastShiftIds->put($employee->id, $shift->id);
            }
        }

        return $matrix;
    }

    private function chooseDayOffs(
        Collection $dates,
        int $preferredOffset,
        Shift $shift,
        Collection $assignments,
        Collection $leaves,
        int $count,
        Collection $alreadySelected,
        array $holidayDates,
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
        $ordered = $available->sortBy(function (Carbon $date) use ($dates, $preferredOffset, $restRecoveryDate) {
            if ($restRecoveryDate?->toDateString() === $date->toDateString()) {
                return -1;
            }

            $index = (int) $dates->search(fn (Carbon $candidate) => $candidate->toDateString() === $date->toDateString());

            return ($index - $preferredOffset + $dates->count()) % $dates->count();
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
