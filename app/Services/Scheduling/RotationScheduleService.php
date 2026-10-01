<?php

namespace App\Services\Scheduling;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PreferredDayOff;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\Shift;
use App\Services\Burnout\BurnoutProtection;
use App\Services\ScheduleService;
use App\Support\ScheduleWeek;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RotationScheduleService
{
    /**
     * What a night shift costs a high-burnout-risk employee in the rotation's
     * ordering. Well below a shift's shortfall (1000 a head), so a night that
     * still needs people is covered before anyone is spared it, but enough to
     * send them to a day shift whenever the nights are already staffed.
     */
    private const PROTECTED_NIGHT_PENALTY = 400;

    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly StaffingRequirementService $staffingRequirements,
        private readonly BurnoutProtection $burnoutProtection,
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

        // Only the charge-cover figure is still answerable on the form; how thin
        // a shift may run stays the unit's own standard, since a ceiling typed
        // for one run is no basis for calling a shift adequately staffed.
        if (isset($data['minimum_senior_per_shift'])) {
            $requirements = $requirements->map(fn (array $requirement): array => [
                'staff' => (int) $requirement['staff'],
                'senior' => (int) $data['minimum_senior_per_shift'],
                'source' => $requirement['source'],
            ]);
        }

        // The ceiling the roster form set: the assistant places nobody beyond it.
        $maximumStaff = isset($data['maximum_staff_per_shift'])
            ? max(1, (int) $data['maximum_staff_per_shift'])
            : null;
        $placedPerShiftDate = [];

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
                ScheduleWeek::start($start)->subDay()->subDays($streakMargin)->toDateString(),
                ScheduleWeek::end($end)->addDay()->addDays($streakMargin)->toDateString(),
            ])
            ->get()
            ->groupBy('employee_id');
        // What the department already has on these shifts and dates, whoever is
        // standing it. The assistant is normally handed a different set of names
        // on a second run over the same period, so a per-employee check would
        // find the week free and rotate a whole second team onto it.
        $alreadyRostered = ScheduleAssignment::query()
            ->where('status', 'scheduled')
            // Only what a previous roster run put there: someone added to a
            // single day by hand is a deliberate one-off the assistant should
            // still be able to staff a shift around.
            ->where('created_via', 'bulk_fill')
            ->whereIn('shift_id', $shifts->pluck('id')->all())
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('employee', fn (Builder $query) => $query->where('department_id', $department->id))
            ->get(['shift_id', 'work_date'])
            ->countBy(fn (ScheduleAssignment $assignment) => $assignment->shift_id.'|'.$assignment->work_date->toDateString());
        // Compared as calendar dates: both of these cast their date column as a
        // plain date and so store a 00:00:00 time with it, which sorts after the
        // bare end-of-range date and would hide a rest day — protected or
        // requested — falling on the last day of the period.
        $existingDayOffs = ScheduleDayOff::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
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
            ->whereDate('preferred_date', '>=', $start->toDateString())
            ->whereDate('preferred_date', '<=', $end->toDateString())
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
        $minimumRestMinutes = max(0, (int) ($data['minimum_rest_hours'] ?? config('schedule.minimum_rest_hours'))) * 60;
        // Employees at high burnout risk: more rest days, no night shift that
        // somebody else can take, and never past the protected weekly limits.
        $protected = $this->burnoutProtection->enabled()
            ? $this->burnoutProtection->protectedAmong($this->burnoutProtection->assessments($employeeIds))
            : collect();
        $rotationMatrix = $this->rotationMatrix(
            $employees,
            $weeks,
            $shifts,
            $previousShiftIds,
            $scheduleMethod,
            $requirements,
            $seniorRankThreshold,
            $protected,
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

                // A shift's own daily turnaround can be shorter than the
                // configured minimum rest (e.g. a 9-to-6 shift under a
                // 16-hour rule can't be worked two days running); when that
                // happens, one day off a week isn't enough to keep the
                // employee off the reactive rest-violation block below, so
                // the requirement is raised to what the shift itself needs.
                //
                // Which days are picked matters as much as how many:
                // everyone resting on the same days (as an unstaggered
                // offset would tend to produce) empties the shift entirely
                // on those days, while spreading employees across the
                // spacing cycle keeps someone on duty every day. Each
                // employee is assigned to one of $restSpacing alternating
                // groups, anchored to the roster's own start date rather
                // than this week's, so the pattern stays continuous across a
                // week boundary instead of resetting its phase every 7 days
                // (7 not being a multiple of most spacings) and forcing a
                // day this employee was actually free to work.
                $restSpacing = $this->restSpacingDays($shift, $minimumRestMinutes);
                $forcedRestDates = $restSpacing > 1
                    ? $weekDates->reject(fn (Carbon $date) => ((int) $start->diffInDays($date)) % $restSpacing === $employeeIndex % $restSpacing)
                    : collect();
                $requestedDaysOff = (int) ($data['days_off_per_week'] ?? 1);
                if ($protected->has($employee->id)) {
                    $requestedDaysOff = max($requestedDaysOff, $this->burnoutProtection->daysOffPerWeek());
                }
                $requiredDaysOff = max(
                    min($requestedDaysOff, max(0, $weekDates->count() - 1)),
                    $forcedRestDates->count(),
                );

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
                    $forcedRestDates,
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

                    $capacityKey = $shift->id.'|'.$date->toDateString();
                    $requiredHere = (int) ($requirements->get($shift->id)['staff'] ?? StaffingRequirementService::FALLBACK_MINIMUM_STAFF);
                    $reason = match (true) {
                        // A published roster already staffs this shift on this
                        // date to the unit's own standard. Proposing more is
                        // exactly how running the assistant twice over one
                        // period ends up with two teams on every day of it.
                        ($alreadyRostered[$capacityKey] ?? 0) >= $requiredHere => RosterDraftService::REASON_ALREADY_ROSTERED,
                        $maximumStaff !== null && ($placedPerShiftDate[$capacityKey] ?? 0) >= $maximumStaff => "{$shift->name} is already at its maximum of {$maximumStaff} staff for this date",
                        default => $this->assignmentBlockReason(
                            $employee,
                            $shift,
                            $date,
                            $employeeAssignments,
                            $employeeDayOffs,
                            $employeeLeaves,
                            $data,
                        ) ?? ($protected->has($employee->id)
                            ? $this->burnoutProtection->blockReason($shift, $date, $employeeAssignments)
                            : null),
                    };
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
                    $placedPerShiftDate[$capacityKey] = ($placedPerShiftDate[$capacityKey] ?? 0) + 1;
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
        $feasibilityWarnings = $this->feasibilityWarnings($employees->count(), $shifts, $requirements, $minimumRestMinutes);

        $notice = "{$patternLabel} fills each shift towards {$department->name}'s recorded coverage standard, then applies the selected days-off, maximum-hours, night-shift, overtime, leave, and rest rules before HR approval.";
        if ($feasibilityWarnings->isNotEmpty()) {
            $notice .= ' '.$feasibilityWarnings->implode(' ');
        }

        return [
            'rows' => $rows,
            'ready_assignments' => $readyAssignments,
            'ready_day_offs' => $readyDayOffs,
            'skipped' => $skipped,
            'assignment_count' => $readyAssignments->count(),
            'day_off_count' => $readyDayOffs->count(),
            'skipped_count' => $skipped->count(),
            'staffing_gaps' => $staffingGaps,
            'notice' => $notice,
            'coverage_standard' => $this->staffingRequirements->derivationSummary($department),
        ];
    }

    /**
     * Flag a shift's requirement as structurally out of reach *before* the
     * roster is built rather than leaving the reviewer to piece it together
     * from a wall of "N shifts below required cover" once the coverage gate
     * blocks publishing. A shift whose own clock span forces employees onto
     * every-other-day (or wider) spacing can only ever put roughly
     * headcount / spacing people on duty at once — no rotation pattern can
     * close a gap past that ceiling; only more staff, a shorter shift, or a
     * relaxed rest rule can.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  Collection<int, array{staff: int, senior: int, source: string}>  $requirements
     * @return Collection<int, string>
     */
    private function feasibilityWarnings(int $eligibleEmployees, Collection $shifts, Collection $requirements, int $minimumRestMinutes): Collection
    {
        if ($eligibleEmployees === 0) {
            return collect();
        }

        return $shifts
            ->map(function (Shift $shift) use ($eligibleEmployees, $requirements, $minimumRestMinutes) {
                $requirement = $requirements->get($shift->id);
                $spacing = $this->restSpacingDays($shift, $minimumRestMinutes);
                if ($requirement === null || $requirement['staff'] <= 0 || $spacing <= 1) {
                    return null;
                }

                // Staggering splits the team into $spacing alternating
                // groups as evenly as the headcount allows; the smallest of
                // those groups is what actually caps coverage, since that
                // group's day is the worst one the requirement has to
                // survive — not the best-case day the largest group covers.
                $worstCaseSimultaneous = (int) floor($eligibleEmployees / $spacing);
                if ($worstCaseSimultaneous >= $requirement['staff']) {
                    return null;
                }

                return sprintf(
                    '%s needs %d staff, but a %dh minimum rest rule limits each of the %d selected employees to about 1 day in every %d — some days will have as few as %d on duty.',
                    $shift->name,
                    $requirement['staff'],
                    (int) ($minimumRestMinutes / 60),
                    $eligibleEmployees,
                    $spacing,
                    $worstCaseSimultaneous,
                );
            })
            ->filter()
            ->values();
    }

    /**
     * Decide which shift each employee works in each week.
     *
     * Shifts are filled towards their own requirement rather than given an equal
     * share of the team: a unit needing four on mornings and two on nights should
     * not receive three and three. Seniors are steered towards shifts that still
     * have nobody able to take charge.
     *
     * Employees at high burnout risk are placed after everyone else, so the
     * shortfalls are met by the rest of the team first, and are steered off
     * night shifts that are already covered.
     *
     * @param  Collection<int, array{staff: int, senior: int, source: string}>  $requirements
     * @param  Collection<int, mixed>  $protected  Keyed by employee id
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
        Collection $protected = new Collection,
    ): array {
        $matrix = [];
        $lastShiftIds = $previousShiftIds;

        // Swapping every employee at the same week boundary can leave the
        // incoming shift with nobody on its first day. Anyone moving from a
        // late shift to an early one is still inside the minimum-rest window
        // that morning, so chooseDayOffs() rightly rests them all — and they
        // sit out together, which is what left a two-shift rotation
        // permanently short of its own coverage gate. Holding a few employees
        // on their current shift across the changeover keeps each shift
        // staffed through it. It is only affordable once the team is larger
        // than one full set of shifts; below that everybody still rotates.
        $continuityBudget = $scheduleMethod === 'rotation'
            ? max(0, $employees->count() - $shifts->count())
            : 0;

        foreach ($weeks as $weekIndex => $weekDates) {
            $counts = $shifts->mapWithKeys(fn (Shift $shift) => [$shift->id => 0]);
            $seniorCounts = $shifts->mapWithKeys(fn (Shift $shift) => [$shift->id => 0]);
            $continuityUsed = $shifts->mapWithKeys(fn (Shift $shift) => [$shift->id => 0]);
            $continuityLeft = $weekIndex > 0 ? $continuityBudget : 0;

            // Seniors are placed first so the charge cover lands where it is needed
            // before the remaining places are filled. High burnout risk goes to
            // the back of the queue, ahead of rank.
            $ordered = $employees->values()
                ->sortBy([
                    fn (Employee $a, Employee $b) => $protected->has($a->id) <=> $protected->has($b->id),
                    fn (Employee $a, Employee $b) => (int) ($b->position?->seniority_rank ?? 1) <=> (int) ($a->position?->seniority_rank ?? 1),
                ])
                ->values();

            foreach ($ordered as $employee) {
                $employeeIndex = $employees->values()->search(fn (Employee $candidate) => $candidate->id === $employee->id);
                $preferredIndex = ($employeeIndex + ($scheduleMethod === 'rotation' ? $weekIndex : 0)) % $shifts->count();
                $lastShiftId = $lastShiftIds->get($employee->id);
                $isSenior = (int) ($employee->position?->seniority_rank ?? 1) >= $seniorRankThreshold;

                // Each shift keeps back what it needs on duty plus one, and no
                // more, so the rest of the team still rotates. The spare covers
                // the held-back employee who draws the changeover day as their
                // own weekly rest day — without it the shift is right back to
                // being empty on exactly the day this is meant to protect.
                // Seniors are reached first by the ordering above, which also
                // keeps the charge cover continuous across the changeover.
                $keepsShift = $continuityLeft > 0
                    && $lastShiftId !== null
                    && $continuityUsed->get($lastShiftId, 0) < max(1, (int) ($requirements->get($lastShiftId)['staff'] ?? 1)) + 1;

                $isProtected = $protected->has($employee->id);

                $shift = $shifts->sortBy(function (Shift $candidate, int $index) use (
                    $counts, $seniorCounts, $requirements, $lastShiftId, $preferredIndex, $shifts, $scheduleMethod, $isSenior, $keepsShift, $isProtected
                ) {
                    $requirement = $requirements->get($candidate->id, ['staff' => 1, 'senior' => 0]);

                    // Shifts still short of their requirement sort first; once a
                    // shift is satisfied its surplus keeps pushing it down.
                    $shortfall = max(0, $requirement['staff'] - $counts[$candidate->id]);
                    $needPenalty = $shortfall > 0 ? -($shortfall * 1000) : $counts[$candidate->id] * 100;

                    $seniorShortfall = max(0, $requirement['senior'] - $seniorCounts[$candidate->id]);
                    $seniorPenalty = $isSenior && $seniorShortfall > 0 ? -($seniorShortfall * 5000) : 0;

                    $repeatPenalty = match (true) {
                        $scheduleMethod !== 'rotation', $candidate->id !== $lastShiftId => 0,
                        // Small enough that a shift still short of its
                        // requirement (-1000 a head) always outranks holding
                        // someone where they are.
                        $keepsShift => -50,
                        default => 25,
                    };
                    $preferenceDistance = ($index - $preferredIndex + $shifts->count()) % $shifts->count();
                    $burnoutPenalty = $isProtected && $candidate->is_night_shift ? self::PROTECTED_NIGHT_PENALTY : 0;

                    return $seniorPenalty + $needPenalty + $repeatPenalty + $preferenceDistance + $burnoutPenalty;
                })->first();

                $matrix[$weekIndex][$employee->id] = $shift;
                $counts->put($shift->id, $counts->get($shift->id) + 1);

                if ($keepsShift && $shift->id === $lastShiftId) {
                    $continuityUsed->put($shift->id, $continuityUsed->get($shift->id) + 1);
                    $continuityLeft--;
                }

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
     * @param  Collection<int, Carbon>  $forcedDates  Dates the rest-hours rule requires off regardless of preference, so this employee's alternating group keeps its slot in the coverage split
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
        Collection $preferredDates = new Collection,
        ?int $preferredWeeklyOffDay = null,
        Collection $forcedDates = new Collection,
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
        $ordered = $available->sortBy(function (Carbon $date) use ($dates, $preferredOffset, $restRecoveryDate, $preferredDates, $preferredWeeklyOffDay, $forcedDates) {
            $index = (int) $dates->search(fn (Carbon $candidate) => $candidate->toDateString() === $date->toDateString());
            $offsetDistance = ($index - $preferredOffset + $dates->count()) % $dates->count();
            $dateString = $date->toDateString();

            if ($restRecoveryDate?->toDateString() === $dateString) {
                return -1000;
            }

            // Ranked above a mere preference: skipping one of these breaks
            // the alternating-group split chosen for this employee, which is
            // what keeps the shift from emptying out entirely on other days.
            if ($forcedDates->contains(fn (Carbon $forced) => $forced->toDateString() === $dateString)) {
                return -900;
            }

            if ($preferredDates->contains(fn (Carbon $preferred) => $preferred->toDateString() === $dateString)) {
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
        if ($this->hasRestViolation($shift, $date, $assignments, $rules)) {
            return 'Minimum rest period not met';
        }
        if ($this->consecutiveWorkdaysExceeded($date, $assignments)) {
            return 'Maximum consecutive workdays exceeded';
        }

        // Labor Code Art. 91: at least 24 consecutive hours off after every
        // six consecutive workdays, checked against actual elapsed time
        // rather than calendar dates — see ScheduleService::weeklyRestViolated
        // for why a calendar "day off" alone isn't sufficient.
        if ($this->weeklyRestViolated($candidateStart, $assignments)) {
            return 'Weekly rest day not met (Labor Code Art. 91)';
        }

        $weekStart = ScheduleWeek::start($date);
        $weekEnd = ScheduleWeek::end($date);
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

        // Consecutive night shifts are a soft (Tier B) constraint — warned on
        // and justifiable rather than hard-blocked — so it is not evaluated
        // here; see RosterDraftService::evaluate()'s night-streak warning pass.

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

    /**
     * Mirrors {@see ScheduleService::weeklyRestViolated()}: Labor
     * Code Art. 91 requires at least 24 consecutive hours off after every six
     * consecutive workdays, measured against actual elapsed time rather than
     * calendar dates.
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

        $mostRecent = $assignments
            ->map(function (ScheduleAssignment $assignment) {
                [, $end] = $this->scheduleService->intervalFor($assignment->shift, $assignment->work_date->toDateString());

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
     * How many days must separate two working days on this shift for the
     * configured minimum rest to actually be met. A shift's own daily
     * turnaround (24 hours minus its clock span) already clears a modest
     * rest requirement for free; once the requirement exceeds that
     * turnaround, the same employee cannot work this shift on consecutive
     * calendar days at all and needs a wider gap instead — e.g. an 8-to-5
     * shift under a 16-hour rest rule leaves only a 15-hour gap day to day,
     * so it can only be worked every other day (spacing of 2).
     */
    private function restSpacingDays(Shift $shift, int $minimumRestMinutes): int
    {
        if ($minimumRestMinutes <= 0) {
            return 1;
        }

        [$start, $end] = $this->scheduleService->intervalFor($shift, '2000-01-01');
        $clockSpanMinutes = $start->diffInMinutes($end);

        return max(1, (int) ceil(($minimumRestMinutes + $clockSpanMinutes) / 1440));
    }

    private function hasRestViolation(Shift $shift, Carbon $date, Collection $assignments, array $rules = []): bool
    {
        $minimumMinutes = max(0, (int) ($rules['minimum_rest_hours'] ?? config('schedule.minimum_rest_hours'))) * 60;
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
