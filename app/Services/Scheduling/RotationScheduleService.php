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
            ->whereBetween('work_date', [$start->copy()->subDay()->toDateString(), $end->copy()->addDay()->toDateString()])
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
        $rotationMatrix = $this->rotationMatrix($employees, $weeks, $shifts, $previousShiftIds);
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
                $existingDayOff = $employeeDayOffs->first(fn (ScheduleDayOff $dayOff) => $weekDates->contains(
                    fn (Carbon $date) => $date->toDateString() === $dayOff->work_date->toDateString(),
                ));
                $dayOffDate = $existingDayOff?->work_date->copy();

                if ($dayOffDate === null) {
                    $dayOffDate = $this->chooseDayOff(
                        $weekDates,
                        $dayOffOffset,
                        $shift,
                        $employeeAssignments,
                        $employeeLeaves,
                    );
                    if ($dayOffDate !== null) {
                        $readyDayOffs->push(['employee' => $employee, 'date' => $dayOffDate]);
                    } else {
                        $skipped->push([
                            'employee' => $employee->full_name,
                            'date' => $weekDates->first()->toDateString(),
                            'reason' => 'No safe day-off date is available in this week',
                        ]);
                    }
                }
                if ($dayOffDate !== null) {
                    $dayOffOffset = (int) $weekDates->search(
                        fn (Carbon $date) => $date->toDateString() === $dayOffDate->toDateString(),
                    );
                }

                $weekReady = 0;
                $weekSkipped = 0;
                foreach ($weekDates as $date) {
                    if ($dayOffDate?->toDateString() === $date->toDateString()) {
                        continue;
                    }

                    $reason = $this->assignmentBlockReason(
                        $employee,
                        $shift,
                        $date,
                        $employeeAssignments,
                        $employeeDayOffs,
                        $employeeLeaves,
                    );
                    if ($reason !== null) {
                        $skipped->push([
                            'employee' => $employee->full_name,
                            'date' => $date->toDateString(),
                            'shift' => $shift->name,
                            'reason' => $reason,
                        ]);
                        $weekSkipped++;

                        continue;
                    }

                    $readyAssignments->push(['employee' => $employee, 'shift' => $shift, 'date' => $date]);
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
                    'day_off' => $dayOffDate?->toDateString(),
                    'assignments' => $weekReady,
                    'skipped' => $weekSkipped,
                ]);
            }

            $rows->push([
                'employee_id' => $employee->id,
                'employee' => $employee->full_name,
                'employee_number' => $employee->employee_number,
                'weeks' => $employeeWeeks,
            ]);
        }

        return [
            'rows' => $rows,
            'ready_assignments' => $readyAssignments,
            'ready_day_offs' => $readyDayOffs,
            'skipped' => $skipped,
            'assignment_count' => $readyAssignments->count(),
            'day_off_count' => $readyDayOffs->count(),
            'skipped_count' => $skipped->count(),
            'notice' => 'Balanced rotation keeps one shift per employee per 7-day block, assigns one day off, and rotates away from the previous week where possible.',
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

    private function rotationMatrix(Collection $employees, Collection $weeks, Collection $shifts, Collection $previousShiftIds): array
    {
        $matrix = [];
        $lastShiftIds = $previousShiftIds;

        foreach ($weeks as $weekIndex => $weekDates) {
            $counts = $shifts->mapWithKeys(fn (Shift $shift) => [$shift->id => 0]);
            foreach ($employees->values() as $employeeIndex => $employee) {
                $preferredIndex = ($employeeIndex + $weekIndex) % $shifts->count();
                $lastShiftId = $lastShiftIds->get($employee->id);
                $shift = $shifts->sortBy(function (Shift $candidate, int $index) use ($counts, $lastShiftId, $preferredIndex, $shifts) {
                    $balancePenalty = $counts[$candidate->id] * 100;
                    $repeatPenalty = $candidate->id === $lastShiftId ? 25 : 0;
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

    private function chooseDayOff(
        Collection $dates,
        int $preferredOffset,
        Shift $shift,
        Collection $assignments,
        Collection $leaves,
    ): ?Carbon {
        $available = $dates->filter(fn (Carbon $date) => ! $this->hasLeave($leaves, $date)
            && ! $assignments->contains(fn (ScheduleAssignment $assignment) => $assignment->work_date->toDateString() === $date->toDateString()))
            ->values();
        if ($available->isEmpty()) {
            return null;
        }

        $restRecoveryDate = $available->first(fn (Carbon $date) => $this->hasRestViolation($shift, $date, $assignments));
        if ($restRecoveryDate) {
            return $restRecoveryDate->copy();
        }

        $preferredDate = $dates->get(min($preferredOffset, $dates->count() - 1));
        $selected = $available->first(fn (Carbon $date) => $date->toDateString() === $preferredDate?->toDateString())
            ?? $available->first();

        return $selected->copy();
    }

    private function assignmentBlockReason(
        Employee $employee,
        Shift $shift,
        Carbon $date,
        Collection $assignments,
        Collection $dayOffs,
        Collection $leaves,
    ): ?string {
        if ($employee->employment_status !== 'active') {
            return 'Inactive employee';
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

        return null;
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
