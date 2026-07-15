<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\RecurringSchedule;
use App\Models\ScheduleAssignment;
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
                $conflicts = $this->conflictsFor($employee, $shift, $date->toDateString());
                if ($conflicts->isNotEmpty()) {
                    $conflict = $conflicts->first();
                    throw ValidationException::withMessages([
                        'schedule' => "Recurring schedule conflicts on {$date->format('M j, Y')} with {$conflict->shift->name} ({$conflict->shift->formatted_time}).",
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
        $conflicts = $this->conflictsFor($employee, $shift, $workDate, $excludeAssignmentId);

        if ($conflicts->isNotEmpty()) {
            $conflict = $conflicts->first();
            throw ValidationException::withMessages([
                'schedule' => "This assignment overlaps {$conflict->shift->name} on {$conflict->work_date->format('M j, Y')} ({$conflict->shift->formatted_time}).",
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
