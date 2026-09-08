<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RecurringSchedule;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\ScheduleLock;
use App\Models\ScheduleRecommendation;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use App\Services\Scheduling\ScheduleLockService;
use App\Services\Scheduling\StaffingRequirementService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScheduleService
{
    /**
     * Rank 3 is the charge/senior rung, the lowest grade a hospital roster treats
     * as able to take charge of a shift.
     */
    public const DEFAULT_SENIOR_RANK_THRESHOLD = 3;

    /**
     * The first date a roster may still be planned by hand. Once a day has begun
     * its roster is a record of who was expected at work, not a plan, so today
     * and everything before it are read-only here. An approved shift swap can
     * still move today's shift — that runs through ShiftSwapService, not this
     * service, and carries its own request-and-approval trail.
     */
    public function firstEditableDate(): Carbon
    {
        return now(config('schedule.timezone'))->startOfDay()->addDay();
    }

    public function isDateEditable(Carbon|string $workDate): bool
    {
        return $this->asScheduleDate($workDate)->greaterThanOrEqualTo($this->firstEditableDate());
    }

    /**
     * @throws ValidationException
     */
    public function assertDateEditable(Carbon|string $workDate, string $field = 'work_date'): void
    {
        if ($this->isDateEditable($workDate)) {
            return;
        }

        $date = $this->asScheduleDate($workDate);

        throw ValidationException::withMessages([
            $field => $date->isToday()
                ? "Today's schedule is read-only. An approved shift swap is the only way to change ".$date->format('M j, Y').'.'
                : 'Schedules on '.$date->format('M j, Y').' have already passed and can no longer be changed.',
        ]);
    }

    private function asScheduleDate(Carbon|string $workDate): Carbon
    {
        return Carbon::parse(
            $workDate instanceof Carbon ? $workDate->toDateString() : $workDate,
            config('schedule.timezone'),
        )->startOfDay();
    }

    private function resolveRecommendationId(?string $uuid): ?int
    {
        return $uuid === null ? null : ScheduleRecommendation::query()->where('uuid', $uuid)->value('id');
    }

    /**
     * @param  array{employee_id: int, shift_id: int, work_date: string, notes?: string|null}  $data
     */
    public function createAssignment(array $data, User $creator): ScheduleAssignment
    {
        return DB::transaction(function () use ($data, $creator) {
            $employee = Employee::query()->with('department')->findOrFail($data['employee_id']);
            $shift = Shift::query()->findOrFail($data['shift_id']);
            $this->assertDateEditable($data['work_date']);
            $this->ensureSchedulable($employee, $shift);
            $this->ensureNoConflicts($employee, $shift, $data['work_date']);
            $this->ensureUnlocked($employee, $data['work_date']);

            return RosterWriteContext::allow($creator, fn () => ScheduleAssignment::query()->create([
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'work_date' => $data['work_date'],
                'status' => 'scheduled',
                'notes' => $data['notes'] ?? null,
                'created_by' => $creator->id,
                'created_via' => 'manual',
                'source_recommendation_id' => $this->resolveRecommendationId($data['recommendation_id'] ?? null),
            ]));
        });
    }

    /**
     * Resolve the coverage this shift must reach, preferring the unit's recorded
     * standard over a bare default.
     *
     * @param  array<string, mixed>  $data
     * @return array{staff: int, senior: int, source: string}
     */
    private function staffingRequirementFor(array $data, Shift $shift): array
    {
        $department = isset($data['department_id'])
            ? Department::query()->with('shiftRequirements')->find($data['department_id'])
            : null;

        if ($department === null) {
            return ['staff' => StaffingRequirementService::FALLBACK_MINIMUM_STAFF, 'senior' => 0, 'source' => 'default minimum'];
        }

        return app(StaffingRequirementService::class)->forShift($department, $shift);
    }

    /**
     * @param  array{employee_ids: array<int>, shift_id: int, start_date: string, end_date: string, include_weekends?: bool, notes?: string|null}  $data
     * @return array{ready: Collection<int, array{employee: Employee, date: Carbon}>, skipped: Collection<int, array{employee: string, date: string, reason: string}>}
     */
    public function bulkAssignmentPlan(array $data): array
    {
        $employees = Employee::query()
            ->with('position')
            ->whereKey($data['employee_ids'])
            ->orderBy('last_name')
            ->get()
            ->keyBy('id');
        $shift = Shift::query()->findOrFail($data['shift_id']);

        if (! $shift->is_active) {
            throw ValidationException::withMessages(['shift_id' => 'The selected shift is inactive.']);
        }

        $isAdministrativeDepartment = isset($data['department_id'])
            && Department::query()->find($data['department_id'])?->category === Department::CATEGORY_ADMINISTRATIVE;

        $start = Carbon::parse($data['start_date'], config('schedule.timezone'))->startOfDay();
        $end = Carbon::parse($data['end_date'], config('schedule.timezone'))->startOfDay();
        $dates = collect(CarbonPeriod::create($start, $end))
            ->map(fn ($date) => Carbon::instance($date)->timezone(config('schedule.timezone'))->startOfDay())
            ->filter(fn (Carbon $date) => ($data['include_weekends'] ?? false) || ! $date->isWeekend())
            // Administrative offices don't staff Sundays at all, so a bulk fill
            // never proposes one even when "include weekends" is checked.
            ->reject(fn (Carbon $date) => $isAdministrativeDepartment && $date->isSunday())
            ->values();
        if ($dates->isEmpty()) {
            throw ValidationException::withMessages([
                'start_date' => 'Choose a weekday or include weekends in the bulk assignment.',
            ]);
        }
        $employeeIds = $employees->keys()->all();
        // The week-based margin keeps the existing hours/night-shift/days-off
        // checks correct for dates near the range's edges; the extra streak
        // margin is so the new consecutive-workday check can see a run that
        // started before this range, not just the current week.
        $streakMargin = max(1, (int) config('schedule.max_consecutive_workdays'));
        $assignmentsByEmployee = ScheduleAssignment::query()
            ->with('shift')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [
                $start->copy()->startOfWeek()->subDay()->subDays($streakMargin)->toDateString(),
                $end->copy()->endOfWeek()->addDay()->addDays($streakMargin)->toDateString(),
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
        // Compared as calendar dates: schedule_day_offs casts work_date as a
        // plain date and so stores a 00:00:00 time with it, which sorts after
        // the bare end-of-range date and would hide a rest day falling on the
        // last day of the period.
        $dayOffsByEmployee = ScheduleDayOff::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->get()
            ->groupBy('employee_id');
        $ready = collect();
        $skipped = collect();
        // The ceiling the roster form set, and how many are already standing on
        // each date under it.
        $maximumStaff = isset($data['maximum_staff_per_shift'])
            ? max(1, (int) $data['maximum_staff_per_shift'])
            : null;
        $placedPerDate = [];

        foreach ($employees as $employee) {
            $employeeAssignments = $assignmentsByEmployee->get($employee->id, collect());
            foreach ($dates as $date) {
                $dateKey = $date->toDateString();

                // Filling every eligible name onto one shift is what made a
                // roster overshoot; past the ceiling the rest are left off and
                // reported, rather than silently piled on.
                if ($maximumStaff !== null && ($placedPerDate[$dateKey] ?? 0) >= $maximumStaff) {
                    $skipped->push([
                        'employee' => $employee->full_name,
                        'date' => $dateKey,
                        'reason' => "{$shift->name} is already at its maximum of {$maximumStaff} staff for this date",
                    ]);

                    continue;
                }

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
                $placedPerDate[$dateKey] = ($placedPerDate[$dateKey] ?? 0) + 1;
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

        // How thin a shift may run is the unit's own standard, not a number
        // typed into this form — the form's figure is the ceiling above, and a
        // roster that meets a ceiling can still be dangerously short.
        $standard = $this->staffingRequirementFor($data, $shift);
        $minimumStaff = (int) $standard['staff'];
        $minimumSenior = (int) ($data['minimum_senior_per_shift'] ?? $standard['senior']);
        $seniorRank = (int) ($data['senior_rank_threshold'] ?? self::DEFAULT_SENIOR_RANK_THRESHOLD);
        $requirementSource = $standard['source'];

        $staffingGaps = $dates
            ->flatMap(function (Carbon $date) use ($ready, $shift, $minimumStaff, $minimumSenior, $seniorRank, $requirementSource) {
                $onDate = $ready->filter(fn (array $item) => $item['date']->toDateString() === $date->toDateString());
                $gaps = collect();

                if ($onDate->count() < $minimumStaff) {
                    $gaps->push([
                        'date' => $date->toDateString(),
                        'shift' => $shift->name,
                        'label' => 'staff',
                        'available' => $onDate->count(),
                        'required' => $minimumStaff,
                        'suggestion' => 'Required by the '.$requirementSource.'. Select more eligible employees or revise the unit standard.',
                    ]);
                }

                // Head count alone can hide a shift with nobody senior enough to
                // take charge, which is the gap a hospital roster is reviewed for.
                if ($minimumSenior > 0) {
                    $seniorsOnDate = $onDate
                        ->filter(fn (array $item) => (int) ($item['employee']->position?->seniority_rank ?? 1) >= $seniorRank)
                        ->count();

                    if ($seniorsOnDate < $minimumSenior) {
                        $gaps->push([
                            'date' => $date->toDateString(),
                            'shift' => $shift->name,
                            'label' => 'senior staff (rank '.$seniorRank.'+)',
                            'available' => $seniorsOnDate,
                            'required' => $minimumSenior,
                            'suggestion' => 'Add a senior or charge-level employee to this shift so it is not covered by entry-level staff alone.',
                        ]);
                    }
                }

                return $gaps;
            })
            ->values();

        return compact('ready', 'skipped', 'staffingGaps');
    }

    /**
     * @param  array{employee_id: int, shift_id: int, work_date: string, notes?: string|null}  $data
     */
    public function updateAssignment(ScheduleAssignment $assignment, array $data, User $actor): ScheduleAssignment
    {
        return DB::transaction(function () use ($assignment, $data, $actor) {
            // The assignment is leaving its current slot as well as landing in a
            // new one, so both ends of the move must be open.
            $originalEmployee = $assignment->employee()->with('department')->first();
            $employee = Employee::query()->with('department')->findOrFail($data['employee_id']);
            $shift = Shift::query()->findOrFail($data['shift_id']);
            // Both ends again: a started day may neither give a shift up nor take one on.
            $this->assertDateEditable($assignment->work_date);
            $this->assertDateEditable($data['work_date']);
            $this->ensureSchedulable($employee, $shift);
            $this->ensureNoConflicts($employee, $shift, $data['work_date'], $assignment->id);
            $this->ensureUnlocked($employee, $data['work_date']);
            if ($originalEmployee !== null) {
                $this->ensureUnlocked($originalEmployee, $assignment->work_date->toDateString());
            }

            $changes = [
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'work_date' => $data['work_date'],
                'notes' => $data['notes'] ?? null,
            ];
            // Only touched when this save carries a fresh recommendation id —
            // a plain edit must not clobber the assignment's existing
            // provenance with null.
            if (array_key_exists('recommendation_id', $data) && $data['recommendation_id'] !== null) {
                $changes['source_recommendation_id'] = $this->resolveRecommendationId($data['recommendation_id']);
            }

            RosterWriteContext::allow($actor, fn () => $assignment->update($changes));

            return $assignment->refresh();
        });
    }

    /**
     * @param  array{employee_id: int, shift_id: int, start_date: string, end_date: string, recurrence_type: string, weekdays?: array<int>|null, interval_weeks: int, notes?: string|null}  $data
     */
    public function createRecurringSchedule(array $data, User $creator): RecurringSchedule
    {
        return DB::transaction(function () use ($data, $creator) {
            $employee = Employee::query()->with('department')->findOrFail($data['employee_id']);
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

            // Every date's lock/day-off/conflict/rest rule used to run its own
            // round trip; a quarter-long daily recurrence could mean hundreds
            // of them for one click. Each kind is preloaded once for the whole
            // range instead, and every date below is checked against that
            // in-memory set — a fetch window wider than any one date's own
            // conflict/rest window never changes the result, since the actual
            // overlap test is time-based, not date-based.
            $rangeStart = $dates->first();
            $rangeEnd = $dates->last();
            $locks = $employee->department !== null
                ? $this->activeLocksFor($employee->department, $rangeStart, $rangeEnd)
                : collect();
            $dayOffDates = ScheduleDayOff::query()
                ->where('employee_id', $employee->id)
                ->whereBetween('work_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
                ->pluck('work_date')
                ->map(fn ($workDate) => Carbon::parse($workDate)->toDateString())
                ->flip();
            $nearbyAssignments = ScheduleAssignment::query()
                ->with('shift')
                ->where('employee_id', $employee->id)
                ->where('status', 'scheduled')
                ->whereBetween('work_date', [
                    $rangeStart->copy()->subDays(2)->toDateString(),
                    $rangeEnd->copy()->addDays(2)->toDateString(),
                ])
                ->get();
            $minimumRestMinutes = max(0, (int) config('schedule.minimum_rest_hours')) * 60;

            foreach ($dates as $date) {
                $dateString = $date->toDateString();

                $lock = $this->lockCovering($locks, $dateString);
                if ($lock !== null) {
                    throw ValidationException::withMessages([
                        'schedule' => "{$employee->department->name} is locked from {$lock->start_date->format('M j, Y')} to {$lock->end_date->format('M j, Y')}. Unlock it before making changes to {$date->format('M j, Y')}.",
                    ]);
                }

                if ($dayOffDates->has($dateString)) {
                    throw ValidationException::withMessages([
                        'schedule' => 'This employee has a scheduled day off on '.$date->format('M j, Y').'. Remove the day off before assigning a shift.',
                    ]);
                }

                [$candidateStart, $candidateEnd] = $this->intervalFor($shift, $dateString);

                $conflicts = $this->filterConflicts($nearbyAssignments, $candidateStart, $candidateEnd);
                if ($conflicts->isNotEmpty()) {
                    $conflict = $conflicts->first();
                    throw ValidationException::withMessages([
                        'schedule' => "Recurring schedule conflicts on {$date->format('M j, Y')} with {$conflict->shift->name} ({$conflict->shift->formatted_time}).",
                    ]);
                }

                if ($minimumRestMinutes > 0
                    && $this->filterRestConflicts($nearbyAssignments, $candidateStart, $candidateEnd, $minimumRestMinutes)->isNotEmpty()) {
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

            RosterWriteContext::allow($creator, function () use ($dates, $employee, $shift, $series, $data, $creator): void {
                foreach ($dates as $date) {
                    ScheduleAssignment::query()->create([
                        'employee_id' => $employee->id,
                        'shift_id' => $shift->id,
                        'recurring_schedule_id' => $series->id,
                        'work_date' => $date->toDateString(),
                        'status' => 'scheduled',
                        'notes' => $data['notes'] ?? null,
                        'created_by' => $creator->id,
                        'created_via' => 'recurring',
                    ]);
                }
            });

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

        $assignments = ScheduleAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [
                $date->copy()->subDay()->toDateString(),
                $date->copy()->addDay()->toDateString(),
            ])
            ->when($excludeAssignmentId, fn ($query) => $query->where('id', '!=', $excludeAssignmentId))
            ->get();

        return $this->filterConflicts($assignments, $candidateStart, $candidateEnd);
    }

    /** @param  Collection<int, ScheduleAssignment>  $assignments */
    private function filterConflicts(Collection $assignments, Carbon $candidateStart, Carbon $candidateEnd): Collection
    {
        return $assignments
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

        $assignments = ScheduleAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [$date->copy()->subDays(2)->toDateString(), $date->copy()->addDays(2)->toDateString()])
            ->when($excludeAssignmentId, fn ($query) => $query->where('id', '!=', $excludeAssignmentId))
            ->get();

        return $this->filterRestConflicts($assignments, $candidateStart, $candidateEnd, $minimumMinutes);
    }

    /** @param  Collection<int, ScheduleAssignment>  $assignments */
    private function filterRestConflicts(Collection $assignments, Carbon $candidateStart, Carbon $candidateEnd, int $minimumMinutes): Collection
    {
        return $assignments
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
     * Labor Code Art. 86 night-shift differential: minutes of this shift
     * that actually fall inside the configured night window (22:00–06:00 by
     * default), computed as a clock overlap rather than an all-or-nothing
     * "is this a night shift" flag — a Night Shift spanning 22:00–07:00 has
     * 480 of its 540 minutes inside the window, not all of them. This is a
     * projection for the publish summary, not a payroll computation: it does
     * not know when within the shift any unpaid meal period falls, so it
     * treats the whole span as worked time.
     */
    public function nightDifferentialMinutes(Shift $shift, string $workDate): int
    {
        [$start, $end] = $this->intervalFor($shift, $workDate);
        $window = config('schedule.night_differential');
        $timezone = config('schedule.timezone');

        $minutes = 0;
        // The window itself can start the day before or land on the day
        // after the shift's own work_date depending on where midnight falls,
        // so every window instance touching a 3-day span around the shift is
        // checked; only a real overlap contributes minutes, so this cannot
        // double-count.
        foreach ([-1, 0, 1] as $dayOffset) {
            $anchor = Carbon::parse($workDate, $timezone)->addDays($dayOffset)->toDateString();
            $windowStart = Carbon::parse($anchor.' '.$window['start'], $timezone);
            $windowEnd = Carbon::parse($anchor.' '.$window['end'], $timezone);
            if ($windowEnd->lessThanOrEqualTo($windowStart)) {
                $windowEnd->addDay();
            }

            $overlapStart = $start->greaterThan($windowStart) ? $start : $windowStart;
            $overlapEnd = $end->lessThan($windowEnd) ? $end : $windowEnd;
            if ($overlapStart->lessThan($overlapEnd)) {
                $minutes += $overlapStart->diffInMinutes($overlapEnd);
            }
        }

        return $minutes;
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
    /**
     * Why this employee cannot work this shift on this date, or null when nothing
     * stands in the way. Public so a hand-edited roster is held to exactly the
     * same rules as a generated one.
     *
     * @param  Collection<int, ScheduleAssignment>  $assignments
     * @param  Collection<int, LeaveRequest>  $leaves
     * @param  Collection<int, ScheduleDayOff>  $dayOffs
     * @param  array<string, mixed>  $rules
     */
    public function bulkAssignmentBlockReason(
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

        if ($employee->isArchived()) {
            return 'Archived employee';
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

        $minimumMinutes = max(0, (int) ($rules['minimum_rest_hours'] ?? config('schedule.minimum_rest_hours'))) * 60;
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

        if ($this->consecutiveWorkdaysExceeded($date, $assignments)) {
            return 'Maximum consecutive workdays exceeded';
        }

        // Labor Code Art. 91: at least 24 consecutive hours off after every
        // six consecutive workdays. A calendar "day off" between two shifts
        // does not by itself guarantee this — a night shift ending 7 AM
        // followed by a 6 AM start two calendar days later is only 23 hours,
        // so this is checked against actual elapsed time, not date gaps.
        if ($this->weeklyRestViolated($candidateStart, $assignments)) {
            return 'Weekly rest day not met (Labor Code Art. 91)';
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

        // Consecutive night shifts are a soft (Tier B) constraint — warned on
        // and justifiable rather than hard-blocked — so unlike the checks
        // above it is not evaluated here; see RosterDraftService::evaluate()'s
        // night-streak warning pass instead.

        return null;
    }

    private function isNightShift(Shift $shift): bool
    {
        return $shift->is_night_shift;
    }

    /**
     * Unlike the days-off-per-week rule above (which only counts distinct
     * dates inside one ISO week and misses a run that crosses a week
     * boundary), this walks the actual streak of consecutive scheduled dates
     * around the candidate date, in either direction.
     *
     * @param  Collection<int, ScheduleAssignment>  $assignments
     */
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

    /**
     * Labor Code Art. 91: after every six consecutive workdays, the next
     * shift must start at least a full rest day's worth of hours later.
     * Unlike {@see consecutiveWorkdaysExceeded}, which only counts calendar
     * dates, this measures the actual gap from the end of the last shift in
     * that streak to the candidate's start — a calendar "day off" between
     * two shifts does not by itself guarantee 24 consecutive hours when
     * shift times don't align to midnight (a night shift ending 7 AM
     * followed by a 6 AM start two calendar days later is only 23 hours).
     *
     * @param  Collection<int, ScheduleAssignment>  $assignments
     */
    private function weeklyRestViolated(Carbon $candidateStart, Collection $assignments): bool
    {
        $requiredDays = (int) config('schedule.max_consecutive_workdays');
        $requiredMinutes = max(0, (int) config('schedule.weekly_rest_hours')) * 60;
        if ($requiredDays <= 0 || $requiredMinutes <= 0) {
            return false;
        }

        // Only the most recent shift ending before the candidate matters —
        // Art. 91 is about the rest immediately preceding this placement,
        // not the employee's whole history.
        $mostRecent = $assignments
            ->map(function (ScheduleAssignment $assignment) {
                [, $end] = $this->intervalFor($assignment->shift, $assignment->work_date->toDateString());

                return ['assignment' => $assignment, 'end' => $end];
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

    /**
     * A per-week night-shift count misses a streak that crosses a week
     * boundary, so a limit of 6/week with one day off could still hand
     * someone six night shifts in a row. This walks the actual consecutive
     * run of night-shift dates around the candidate date, in either
     * direction, the same way {@see consecutiveWorkdaysExceeded} does for
     * all-shift streaks.
     *
     * Public, and no longer consulted by {@see bulkAssignmentBlockReason}:
     * consecutive night shifts are a Tier B (soft, justify-and-proceed)
     * constraint, not a hard block, so this is called directly by
     * RosterDraftService's night-streak warning pass instead.
     *
     * @param  Collection<int, ScheduleAssignment>  $assignments
     */
    public function consecutiveNightShiftsExceeded(Carbon $date, Collection $assignments, int $maxConsecutiveNights): bool
    {
        if ($maxConsecutiveNights <= 0) {
            return false;
        }

        $nightDates = $assignments
            ->filter(fn (ScheduleAssignment $assignment) => $this->isNightShift($assignment->shift))
            ->pluck('work_date')
            ->map(fn ($workDate) => Carbon::parse($workDate)->toDateString())
            ->unique()
            ->flip();

        $streak = 1;
        $cursor = $date->copy()->subDay();
        while ($nightDates->has($cursor->toDateString())) {
            $streak++;
            $cursor->subDay();
        }
        $cursor = $date->copy()->addDay();
        while ($nightDates->has($cursor->toDateString())) {
            $streak++;
            $cursor->addDay();
        }

        return $streak > $maxConsecutiveNights;
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

        // The request rules refuse an archived employee at the door; this is
        // the same answer for every path that reaches the write without going
        // through one — a service call, a queued job, a future caller.
        if ($employee->isArchived()) {
            throw ValidationException::withMessages(['employee_id' => $employee->full_name.' is archived and can no longer be scheduled.']);
        }

        if (! $shift->is_active) {
            throw ValidationException::withMessages(['shift_id' => 'The selected shift is inactive.']);
        }
    }

    private function ensureUnlocked(Employee $employee, string $workDate): void
    {
        if ($employee->department === null) {
            return;
        }

        app(ScheduleLockService::class)->assertUnlocked(
            $employee->department,
            Carbon::parse($workDate, config('schedule.timezone')),
        );
    }

    /** @return Collection<int, ScheduleLock> */
    private function activeLocksFor(Department $department, Carbon $start, Carbon $end): Collection
    {
        return ScheduleLock::query()
            ->where('department_id', $department->id)
            ->whereNull('unlocked_at')
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get();
    }

    /** @param  Collection<int, ScheduleLock>  $locks */
    private function lockCovering(Collection $locks, string $workDate): ?ScheduleLock
    {
        return $locks->first(fn (ScheduleLock $lock) => $workDate >= $lock->start_date->toDateString()
            && $workDate <= $lock->end_date->toDateString());
    }
}
