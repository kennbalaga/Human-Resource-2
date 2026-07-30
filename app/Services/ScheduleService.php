<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RecurringSchedule;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScheduleService
{
    /**
     * @param  array{employee_id: int, shift_id: int, work_date: string, notes?: string|null}  $data
     */
    public function createAssignment(array $data, User $creator): ScheduleAssignment
    {
        return DB::transaction(function () use ($data, $creator) {
            $employee = Employee::query()->findOrFail($data['employee_id']);
            $shift = Shift::query()->findOrFail($data['shift_id']);
            $this->ensureSchedulable($employee, $shift);
            $this->ensureNoConflicts($employee, $shift, $data['work_date']);

            return ScheduleAssignment::query()->create([
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'work_date' => $data['work_date'],
                'status' => 'scheduled',
                'notes' => $data['notes'] ?? null,
                'created_by' => $creator->id,
            ]);
        });
    }

    /**
     * @param  array{employee_ids: array<int>, shift_id: int, start_date: string, end_date: string, include_weekends?: bool, notes?: string|null}  $data
     * @return array{ready: Collection<int, array{employee: Employee, date: Carbon}>, skipped: Collection<int, array{employee: string, date: string, reason: string}>}
     */
    public function bulkAssignmentPlan(array $data): array
    {
        $employees = Employee::query()
            ->whereKey($data['employee_ids'])
            ->orderBy('last_name')
            ->get()
            ->keyBy('id');
        $shift = Shift::query()->findOrFail($data['shift_id']);

        if (! $shift->is_active) {
            throw ValidationException::withMessages(['shift_id' => 'The selected shift is inactive.']);
        }

        $start = Carbon::parse($data['start_date'], config('schedule.timezone'))->startOfDay();
        $end = Carbon::parse($data['end_date'], config('schedule.timezone'))->startOfDay();
        $dates = collect(CarbonPeriod::create($start, $end))
            ->map(fn ($date) => Carbon::instance($date)->timezone(config('schedule.timezone'))->startOfDay())
            ->filter(fn (Carbon $date) => ($data['include_weekends'] ?? false) || ! $date->isWeekend())
            ->values();
        if ($dates->isEmpty()) {
            throw ValidationException::withMessages([
                'start_date' => 'Choose a weekday or include weekends in the bulk assignment.',
            ]);
        }
        $employeeIds = $employees->keys()->all();
        $assignmentsByEmployee = ScheduleAssignment::query()
            ->with('shift')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [
                $start->copy()->startOfWeek()->subDay()->toDateString(),
                $end->copy()->endOfWeek()->addDay()->toDateString(),
            ])
            ->get()
            ->groupBy('employee_id');
        $leavesByEmployee = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get()
            ->groupBy('employee_id');
        $dayOffsByEmployee = ScheduleDayOff::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('employee_id');
        $ready = collect();
        $skipped = collect();

        foreach ($employees as $employee) {
            $employeeAssignments = $assignmentsByEmployee->get($employee->id, collect());
            foreach ($dates as $date) {
                $reason = $this->bulkAssignmentBlockReason(
                    $employee,
                    $shift,
                    $date,
                    $employeeAssignments,
                    $leavesByEmployee->get($employee->id, collect()),
                    $dayOffsByEmployee->get($employee->id, collect()),
                    $data,
                );

                if ($reason !== null) {
                    $skipped->push([
                        'employee' => $employee->full_name,
                        'date' => $date->toDateString(),
                        'reason' => $reason,
                    ]);

                    continue;
                }

                $ready->push(['employee' => $employee, 'date' => $date]);
                $plannedAssignment = new ScheduleAssignment([
                    'employee_id' => $employee->id,
                    'shift_id' => $shift->id,
                    'work_date' => $date->toDateString(),
                    'status' => 'scheduled',
                ]);
                $plannedAssignment->setRelation('shift', $shift);
                $employeeAssignments->push($plannedAssignment);
            }
        }

        $minimumStaff = (int) ($data['minimum_staff_per_shift'] ?? 1);
        $staffingGaps = $dates
            ->map(function (Carbon $date) use ($ready, $shift, $minimumStaff) {
                $available = $ready
                    ->filter(fn (array $item) => $item['date']->toDateString() === $date->toDateString())
                    ->count();

                return $available < $minimumStaff ? [
                    'date' => $date->toDateString(),
                    'shift' => $shift->name,
                    'available' => $available,
                    'required' => $minimumStaff,
                    'suggestion' => 'Select more eligible employees or lower the minimum staffing rule.',
                ] : null;
            })
            ->filter()
            ->values();

        return compact('ready', 'skipped', 'staffingGaps');
    }

    /**
     * @param  array{employee_ids: array<int>, shift_id: int, start_date: string, end_date: string, include_weekends?: bool, notes?: string|null}  $data
     * @return array{assignments: Collection<int, ScheduleAssignment>, skipped: Collection<int, array{employee: string, date: string, reason: string}>}
     */
    public function createBulkAssignments(array $data, User $creator): array
    {
        return DB::transaction(function () use ($data, $creator) {
            $plan = $this->bulkAssignmentPlan($data);
            $assignments = $plan['ready']->map(fn (array $item) => ScheduleAssignment::query()->create([
                'employee_id' => $item['employee']->id,
                'shift_id' => $data['shift_id'],
                'work_date' => $item['date']->toDateString(),
                'status' => 'scheduled',
                'notes' => $data['notes'] ?? null,
                'created_by' => $creator->id,
            ]));

            return ['assignments' => $assignments, 'skipped' => $plan['skipped']];
        });
    }

    /**
     * @param  array{employee_id: int, shift_id: int, work_date: string, notes?: string|null}  $data
     */
    public function updateAssignment(ScheduleAssignment $assignment, array $data): ScheduleAssignment
    {
        return DB::transaction(function () use ($assignment, $data) {
            $employee = Employee::query()->findOrFail($data['employee_id']);
            $shift = Shift::query()->findOrFail($data['shift_id']);
            $this->ensureSchedulable($employee, $shift);
            $this->ensureNoConflicts($employee, $shift, $data['work_date'], $assignment->id);

            $assignment->update([
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'work_date' => $data['work_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            return $assignment->refresh();
        });
    }

    /**
     * @param  array{employee_id: int, shift_id: int, start_date: string, end_date: string, recurrence_type: string, weekdays?: array<int>|null, interval_weeks: int, notes?: string|null}  $data
     */
    public function createRecurringSchedule(array $data, User $creator): RecurringSchedule
    {
        return DB::transaction(function () use ($data, $creator) {
            $employee = Employee::query()->findOrFail($data['employee_id']);
            $shift = Shift::query()->findOrFail($data['shift_id']);
            $this->ensureSchedulable($employee, $shift);

            $dates = $this->recurrenceDates(
                $data['start_date'],
                $data['end_date'],
                $data['recurrence_type'],
                $data['weekdays'] ?? [],
                $data['interval_weeks'],
            );

            if ($dates->isEmpty()) {
                throw ValidationException::withMessages([
                    'weekdays' => 'The recurrence rule does not generate any schedule dates.',
                ]);
            }

            foreach ($dates as $date) {
                $this->ensureNoDayOff($employee, $date->toDateString());
                $conflicts = $this->conflictsFor($employee, $shift, $date->toDateString());
                if ($conflicts->isNotEmpty()) {
                    $conflict = $conflicts->first();
                    throw ValidationException::withMessages([
                        'schedule' => "Recurring schedule conflicts on {$date->format('M j, Y')} with {$conflict->shift->name} ({$conflict->shift->formatted_time}).",
                    ]);
                }
                if ($this->restConflictsFor($employee, $shift, $date->toDateString())->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'schedule' => "Recurring schedule does not provide the configured minimum rest before or after {$date->format('M j, Y')}.",
                    ]);
                }
            }

            $series = RecurringSchedule::query()->create([
                'uuid' => (string) Str::uuid(),
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'recurrence_type' => $data['recurrence_type'],
                'weekdays' => $data['recurrence_type'] === 'weekly' ? array_values($data['weekdays']) : null,
                'interval_weeks' => $data['interval_weeks'],
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
                'created_by' => $creator->id,
            ]);

            foreach ($dates as $date) {
                ScheduleAssignment::query()->create([
                    'employee_id' => $employee->id,
                    'shift_id' => $shift->id,
                    'recurring_schedule_id' => $series->id,
                    'work_date' => $date->toDateString(),
                    'status' => 'scheduled',
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $creator->id,
                ]);
            }

            return $series->loadCount('assignments');
        });
    }

    /**
     * @return Collection<int, ScheduleAssignment>
     */
    public function conflictsFor(
        Employee $employee,
        Shift $shift,
        string $workDate,
        ?int $excludeAssignmentId = null,
    ): Collection {
        [$candidateStart, $candidateEnd] = $this->intervalFor($shift, $workDate);
        $date = Carbon::parse($workDate, config('schedule.timezone'));

        return ScheduleAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [
                $date->copy()->subDay()->toDateString(),
                $date->copy()->addDay()->toDateString(),
            ])
            ->when($excludeAssignmentId, fn ($query) => $query->where('id', '!=', $excludeAssignmentId))
            ->get()
            ->filter(function (ScheduleAssignment $existing) use ($candidateStart, $candidateEnd) {
                [$existingStart, $existingEnd] = $this->intervalFor($existing->shift, $existing->work_date->toDateString());

                return $candidateStart->lessThan($existingEnd) && $candidateEnd->greaterThan($existingStart);
            })
            ->values();
    }

    public function dayOffFor(Employee $employee, string $workDate): ?ScheduleDayOff
    {
        return ScheduleDayOff::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $workDate)
            ->first();
    }

    /** @return Collection<int, ScheduleAssignment> */
    public function restConflictsFor(
        Employee $employee,
        Shift $shift,
        string $workDate,
        ?int $excludeAssignmentId = null,
    ): Collection {
        $minimumMinutes = max(0, (int) config('schedule.minimum_rest_hours')) * 60;
        if ($minimumMinutes === 0) {
            return collect();
        }

        [$candidateStart, $candidateEnd] = $this->intervalFor($shift, $workDate);
        $date = Carbon::parse($workDate, config('schedule.timezone'));

        return ScheduleAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [$date->copy()->subDays(2)->toDateString(), $date->copy()->addDays(2)->toDateString()])
            ->when($excludeAssignmentId, fn ($query) => $query->where('id', '!=', $excludeAssignmentId))
            ->get()
            ->filter(function (ScheduleAssignment $existing) use ($candidateStart, $candidateEnd, $minimumMinutes) {
                [$existingStart, $existingEnd] = $this->intervalFor($existing->shift, $existing->work_date->toDateString());
                if ($candidateStart->lessThan($existingEnd) && $candidateEnd->greaterThan($existingStart)) {
                    return false;
                }
                if ($candidateStart->greaterThanOrEqualTo($existingEnd)) {
                    return $existingEnd->diffInMinutes($candidateStart) < $minimumMinutes;
                }
                if ($existingStart->greaterThanOrEqualTo($candidateEnd)) {
                    return $candidateEnd->diffInMinutes($existingStart) < $minimumMinutes;
                }

                return false;
            })
            ->values();
    }

    /**
     * @return array{Carbon, Carbon}
     */
    public function intervalFor(Shift $shift, string $workDate): array
    {
        $timezone = config('schedule.timezone');
        $start = Carbon::parse($workDate.' '.$shift->start_time, $timezone);
        $end = Carbon::parse($workDate.' '.$shift->end_time, $timezone);

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }

    /**
     * @param  array<int>  $weekdays
     * @return Collection<int, Carbon>
     */
    public function recurrenceDates(
        string $startDate,
        string $endDate,
        string $recurrenceType,
        array $weekdays,
        int $intervalWeeks,
    ): Collection {
        $start = Carbon::parse($startDate, config('schedule.timezone'))->startOfDay();
        $end = Carbon::parse($endDate, config('schedule.timezone'))->startOfDay();
        $startWeek = $start->copy()->startOfWeek(Carbon::MONDAY);

        return collect(CarbonPeriod::create($start, $end))
            ->map(fn ($date) => Carbon::instance($date)->timezone(config('schedule.timezone')))
            ->filter(function (Carbon $date) use ($recurrenceType, $weekdays, $intervalWeeks, $startWeek) {
                if ($recurrenceType === 'daily') {
                    return true;
                }

                $weekOffset = (int) floor($startWeek->diffInWeeks($date->copy()->startOfWeek(Carbon::MONDAY)));

                return in_array($date->dayOfWeekIso, $weekdays, true)
                    && $weekOffset % $intervalWeeks === 0;
            })
            ->values();
    }

    private function ensureNoConflicts(
        Employee $employee,
        Shift $shift,
        string $workDate,
        ?int $excludeAssignmentId = null,
    ): void {
        $this->ensureNoDayOff($employee, $workDate);
        $conflicts = $this->conflictsFor($employee, $shift, $workDate, $excludeAssignmentId);

        if ($conflicts->isNotEmpty()) {
            $conflict = $conflicts->first();
            throw ValidationException::withMessages([
                'schedule' => "This assignment overlaps {$conflict->shift->name} on {$conflict->work_date->format('M j, Y')} ({$conflict->shift->formatted_time}).",
            ]);
        }

        $restConflicts = $this->restConflictsFor($employee, $shift, $workDate, $excludeAssignmentId);
        if ($restConflicts->isNotEmpty()) {
            $conflict = $restConflicts->first();
            throw ValidationException::withMessages([
                'schedule' => "This assignment does not provide the configured minimum rest before or after {$conflict->shift->name} on {$conflict->work_date->format('M j, Y')}.",
            ]);
        }
    }

    /**
     * @param  Collection<int, ScheduleAssignment>  $assignments
     * @param  Collection<int, LeaveRequest>  $leaves
     * @param  Collection<int, ScheduleDayOff>  $dayOffs
     */
    private function bulkAssignmentBlockReason(
        Employee $employee,
        Shift $shift,
        Carbon $date,
        Collection $assignments,
        Collection $leaves,
        Collection $dayOffs,
        array $rules = [],
    ): ?string {
        if ($employee->employment_status !== 'active') {
            return 'Inactive employee';
        }

        if (in_array($date->toDateString(), $rules['holiday_dates'] ?? [], true)) {
            return 'Holiday or closure date';
        }

        if ($leaves->contains(fn (LeaveRequest $leave) => $date->toDateString() >= $leave->start_date->toDateString()
            && $date->toDateString() <= $leave->end_date->toDateString())) {
            return 'Approved leave';
        }

        if ($dayOffs->contains(fn (ScheduleDayOff $dayOff) => $dayOff->work_date->toDateString() === $date->toDateString())) {
            return 'Scheduled day off';
        }

        [$candidateStart, $candidateEnd] = $this->intervalFor($shift, $date->toDateString());
        $hasConflict = $assignments->contains(function (ScheduleAssignment $assignment) use ($candidateStart, $candidateEnd) {
            [$existingStart, $existingEnd] = $this->intervalFor($assignment->shift, $assignment->work_date->toDateString());

            return $candidateStart->lessThan($existingEnd) && $candidateEnd->greaterThan($existingStart);
        });

        if ($hasConflict) {
            return 'Overlapping schedule';
        }

        $minimumMinutes = max(0, (int) config('schedule.minimum_rest_hours')) * 60;
        if ($minimumMinutes > 0 && $assignments->contains(function (ScheduleAssignment $assignment) use ($candidateStart, $candidateEnd, $minimumMinutes) {
            [$existingStart, $existingEnd] = $this->intervalFor($assignment->shift, $assignment->work_date->toDateString());
            if ($candidateStart->greaterThanOrEqualTo($existingEnd)) {
                return $existingEnd->diffInMinutes($candidateStart) < $minimumMinutes;
            }
            if ($existingStart->greaterThanOrEqualTo($candidateEnd)) {
                return $candidateEnd->diffInMinutes($existingStart) < $minimumMinutes;
            }

            return false;
        })) {
            return 'Minimum rest period not met';
        }

        $weekStart = $date->copy()->startOfWeek();
        $weekEnd = $date->copy()->endOfWeek();
        $weeklyAssignments = $assignments->filter(fn (ScheduleAssignment $assignment) => $assignment->work_date->betweenIncluded($weekStart, $weekEnd));
        $daysOffPerWeek = (int) ($rules['days_off_per_week'] ?? 0);
        if ($daysOffPerWeek > 0 && $weeklyAssignments->pluck('work_date')->map->toDateString()->unique()->count() >= 7 - $daysOffPerWeek) {
            return 'Days-off rule would be exceeded';
        }

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

    private function ensureNoDayOff(Employee $employee, string $workDate): void
    {
        if ($this->dayOffFor($employee, $workDate)) {
            throw ValidationException::withMessages([
                'schedule' => 'This employee has a scheduled day off on '.Carbon::parse($workDate)->format('M j, Y').'. Remove the day off before assigning a shift.',
            ]);
        }
    }

    private function ensureSchedulable(Employee $employee, Shift $shift): void
    {
        if ($employee->employment_status !== 'active') {
            throw ValidationException::withMessages(['employee_id' => 'Only active employees may be scheduled.']);
        }

        if (! $shift->is_active) {
            throw ValidationException::withMessages(['shift_id' => 'The selected shift is inactive.']);
        }
    }
}
