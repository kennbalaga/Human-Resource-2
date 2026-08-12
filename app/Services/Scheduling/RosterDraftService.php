<?php

namespace App\Services\Scheduling;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RosterDraft;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\ScheduleLock;
use App\Models\Shift;
use App\Models\User;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Works with a roster exactly as it stands on screen.
 *
 * The generators answer "what would a good roster look like"; this answers "is
 * the roster in front of me publishable, and where is it short". Keeping the two
 * apart is what lets the assistant propose a draft that the nursing office can
 * then change, instead of the roster being regenerated at the moment it is saved
 * and quietly discarding those changes.
 */
class RosterDraftService
{
    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly StaffingRequirementService $staffingRequirements,
    ) {}

    /**
     * @param  Collection<int, array{employee_id: int, shift_id: int|null, work_date: string}>  $entries
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function evaluate(Department $department, Collection $entries, string $startDate, string $endDate, array $rules = []): array
    {
        $start = Carbon::parse($startDate, config('schedule.timezone'))->startOfDay();
        $end = Carbon::parse($endDate, config('schedule.timezone'))->startOfDay();
        $dates = collect(CarbonPeriod::create($start, $end))
            ->map(fn ($date) => Carbon::instance($date)->timezone(config('schedule.timezone'))->startOfDay())
            // Administrative offices (HR, Finance, IT, ...) run Monday–Saturday;
            // the board shouldn't offer, or gate publishing on, a Sunday shift
            // those departments never actually staff.
            ->when(
                $department->category === Department::CATEGORY_ADMINISTRATIVE,
                fn (Collection $dates) => $dates->reject(fn (Carbon $date) => $date->isSunday())->values(),
            );

        $employees = Employee::query()
            ->with('position')
            ->whereKey($entries->pluck('employee_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $shifts = Shift::query()
            ->whereKey($entries->pluck('shift_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        // Every shift *this roster was actually built for* is shown, so one
        // nobody has been placed on yet still appears with its requirement
        // rather than vanishing from the board — but a shift outside that
        // selection (e.g. Administrative, when this run is Night-only) isn't
        // this roster's concern and shouldn't count toward its coverage gate.
        $allShifts = Shift::query()->where('is_active', true)->orderBy('start_time')->get();
        $relevantShiftIds = collect($rules['shift_ids'] ?? [])
            ->push($rules['shift_id'] ?? null)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();
        $relevantShifts = $relevantShiftIds->isNotEmpty()
            ? $allShifts->whereIn('id', $relevantShiftIds->all())->values()
            : $allShifts;
        $requirements = $this->staffingRequirements->forShifts($department, $relevantShifts);

        // A figure typed into the roster form only ever overrides the unit's
        // standing requirement for this one evaluation, same as at generation time.
        if (isset($rules['minimum_staff_per_shift']) || isset($rules['minimum_senior_per_shift'])) {
            $requirements = $requirements->map(fn (array $requirement): array => [
                'staff' => (int) ($rules['minimum_staff_per_shift'] ?? $requirement['staff']),
                'senior' => (int) ($rules['minimum_senior_per_shift'] ?? $requirement['senior']),
                'source' => 'roster form',
            ]);
        }

        $context = $this->contextFor($employees->keys()->all(), $start, $end);
        $issues = collect();
        $seniorRank = (int) ($rules['senior_rank_threshold'] ?? ScheduleService::DEFAULT_SENIOR_RANK_THRESHOLD);

        $working = $entries->filter(fn (array $entry) => $entry['shift_id'] !== null);
        $dayOffEntries = $entries->filter(fn (array $entry) => $entry['shift_id'] === null);

        // Blocking reasons are resolved per entry against the roster as it stands,
        // including the other entries in this same draft.
        $placed = collect();
        foreach ($working as $entry) {
            $employee = $employees->get($entry['employee_id']);
            $shift = $shifts->get($entry['shift_id']);
            $date = Carbon::parse($entry['work_date'], config('schedule.timezone'))->startOfDay();

            if ($employee === null || $shift === null) {
                $issues->push($this->issue($entry, $employee?->full_name ?? 'Unknown employee', $shift?->name, 'Unknown employee or shift'));

                continue;
            }

            $alreadyPlaced = $placed->get($employee->id, collect());
            $reason = $this->scheduleService->bulkAssignmentBlockReason(
                $employee,
                $shift,
                $date,
                $context['assignments']->get($employee->id, collect())->merge($alreadyPlaced),
                $context['leaves']->get($employee->id, collect()),
                $context['dayOffs']->get($employee->id, collect()),
                $rules,
            );

            if ($reason !== null) {
                $issues->push($this->issue($entry, $employee->full_name, $shift->name, $reason));

                continue;
            }

            $pending = new ScheduleAssignment([
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'work_date' => $date->toDateString(),
                'status' => 'scheduled',
            ]);
            $pending->setRelation('shift', $shift);
            $placed->put($employee->id, $alreadyPlaced->push($pending));
        }

        $blocked = $issues->map(fn (array $issue) => $issue['key'])->flip();

        // Consecutive night shifts are a soft (Tier B) constraint: unlike the
        // hard blocks above, a violating entry is still placed on the board —
        // it is only flagged, so publishing it needs a justification on
        // record rather than being refused outright.
        $maxConsecutiveNights = (int) ($rules['max_consecutive_nights'] ?? 4);
        $nightStreakWarnings = collect();
        foreach ($placed as $employeeId => $employeeAssignments) {
            $employee = $employees->get($employeeId);
            $mergedAssignments = $context['assignments']->get($employeeId, collect())->merge($employeeAssignments);

            foreach ($employeeAssignments as $assignment) {
                if (! $assignment->shift->is_night_shift) {
                    continue;
                }

                if ($this->scheduleService->consecutiveNightShiftsExceeded($assignment->work_date->copy(), $mergedAssignments, $maxConsecutiveNights)) {
                    $nightStreakWarnings->push([
                        'employee_id' => $employeeId,
                        'employee' => $employee?->full_name ?? 'Unknown',
                        'work_date' => $assignment->work_date->toDateString(),
                        'shift' => $assignment->shift->name,
                    ]);
                }
            }
        }

        $days = $dates->map(function (Carbon $date) use ($working, $dayOffEntries, $employees, $relevantShifts, $requirements, $seniorRank, $blocked) {
            $dateString = $date->toDateString();

            $shiftRows = $relevantShifts->map(function (Shift $shift) use ($working, $employees, $requirements, $seniorRank, $blocked, $dateString) {
                $assigned = $working
                    ->filter(fn (array $entry) => $entry['work_date'] === $dateString && (int) $entry['shift_id'] === $shift->id)
                    ->map(function (array $entry) use ($employees, $seniorRank, $blocked) {
                        $employee = $employees->get($entry['employee_id']);
                        $rank = (int) ($employee?->position?->seniority_rank ?? 1);

                        return [
                            'employee_id' => $entry['employee_id'],
                            'name' => $employee?->full_name ?? 'Unknown',
                            'employee_number' => $employee?->employee_number,
                            'position' => $employee?->position?->title,
                            'is_senior' => $rank >= $seniorRank,
                            'blocked' => $blocked->has($this->entryKey($entry)),
                        ];
                    })
                    ->values();

                $requirement = $requirements->get($shift->id, ['staff' => 1, 'senior' => 0, 'source' => 'default minimum']);
                $placeable = $assigned->reject(fn (array $row) => $row['blocked']);

                return [
                    'shift_id' => $shift->id,
                    'shift' => $shift->name,
                    'time' => $shift->formatted_time ?? null,
                    'is_night' => $shift->is_night_shift,
                    'assigned' => $assigned->values()->all(),
                    'count' => $placeable->count(),
                    'required' => $requirement['staff'],
                    'senior_count' => $placeable->where('is_senior', true)->count(),
                    'senior_required' => $requirement['senior'],
                    'requirement_source' => $requirement['source'],
                    'meets_requirement' => $placeable->count() >= $requirement['staff']
                        && $placeable->where('is_senior', true)->count() >= $requirement['senior'],
                ];
            })->values();

            $offToday = $dayOffEntries
                ->filter(fn (array $entry) => $entry['work_date'] === $dateString)
                ->map(fn (array $entry) => [
                    'employee_id' => $entry['employee_id'],
                    'name' => $employees->get($entry['employee_id'])?->full_name ?? 'Unknown',
                    'employee_number' => $employees->get($entry['employee_id'])?->employee_number,
                ])
                ->values();

            return [
                'date' => $dateString,
                'weekday' => $date->format('l'),
                'is_weekend' => $date->isWeekend(),
                'shifts' => $shiftRows->all(),
                'day_offs' => $offToday->all(),
                'fully_covered' => $shiftRows->every(fn (array $row) => $row['meets_requirement']),
            ];
        })->values();

        return [
            'days' => $days->all(),
            'issues' => $issues->map(fn (array $issue) => collect($issue)->except('key')->all())->values()->all(),
            'night_streak_warnings' => $nightStreakWarnings->values()->all(),
            'summary' => [
                'assignments' => $working->count() - $issues->count(),
                'day_offs' => $dayOffEntries->count(),
                'blocked' => $issues->count(),
                'shifts_short' => $days->sum(fn (array $day) => collect($day['shifts'])->reject(fn (array $row) => $row['meets_requirement'])->count()),
                'night_streak_warnings' => $nightStreakWarnings->count(),
                // For the Step 5 publish summary: who this roster actually
                // touches, and the projected Art. 86 night-differential
                // hours — both derived from the same placements the board
                // itself shows. Night-differential hours is a clock-overlap
                // computation (see ScheduleService::nightDifferentialMinutes),
                // not a count of "night shifts", since only part of a shift
                // like 10 PM–7 AM actually falls inside the differential
                // window.
                'employees_affected' => $placed->keys()->merge($dayOffEntries->pluck('employee_id'))->unique()->count(),
                'night_differential_hours' => round($placed->flatten(1)->sum(
                    fn (ScheduleAssignment $assignment) => $this->scheduleService->nightDifferentialMinutes($assignment->shift, $assignment->work_date->toDateString())
                ) / 60, 1),
            ],
            'coverage_standard' => $this->staffingRequirements->derivationSummary($department),
        ];
    }

    /**
     * Write the roster exactly as supplied. Entries that cannot be scheduled are
     * reported back rather than silently dropped or silently replaced.
     *
     * @param  Collection<int, array{employee_id: int, shift_id: int|null, work_date: string}>  $entries
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function publish(Department $department, Collection $entries, User $creator, ?string $notes = null, ?RosterDraft $draft = null, array $rules = []): array
    {
        return DB::transaction(function () use ($department, $entries, $creator, $notes, $draft, $rules) {
            $employeeIds = $entries->pluck('employee_id')->unique()->all();
            $employees = Employee::query()->whereKey($employeeIds)->lockForUpdate()->get()->keyBy('id');

            $dates = $entries->pluck('work_date');
            $evaluation = $this->evaluate($department, $entries, $dates->min(), $dates->max(), $rules);
            $blocked = collect($evaluation['issues'])
                ->map(fn (array $issue) => $issue['employee_id'].'|'.$issue['work_date'])
                ->flip();

            // Tier A (hard constraint, no override): minimum staff and senior
            // coverage must actually be met for every shift in the period.
            // The only way past this is to fix the roster or edit the
            // requirement itself — never a typed reason, so this check does
            // not accept one.
            if (($evaluation['summary']['shifts_short'] ?? 0) > 0) {
                throw ValidationException::withMessages([
                    'shifts_short' => 'This roster leaves shifts below their required minimum staff or senior cover. Coverage must actually be met — fix the roster or adjust the requirement before publishing.',
                ]);
            }

            // Tier B (soft constraint, warn and justify): a consecutive-night
            // streak beyond the configured limit is still publishable, but
            // only with a reason on record — the board's own Next gate
            // mirrors this same check.
            if (($evaluation['summary']['night_streak_warnings'] ?? 0) > 0 && trim((string) ($rules['night_streak_justification'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'night_streak_justification' => 'This roster schedules one or more employees beyond the maximum consecutive night shifts. Enter a justification before publishing.',
                ]);
            }

            $auditTrail = collect([
                ($rules['overtime_allowed'] ?? false) ? 'Overtime justification: '.trim((string) ($rules['overtime_justification'] ?? '')) : null,
                ($evaluation['summary']['night_streak_warnings'] ?? 0) > 0 ? 'Consecutive night shift justification: '.trim((string) ($rules['night_streak_justification'] ?? '')) : null,
            ])->filter()->implode(' — ');
            $notes = trim(collect([$notes, $auditTrail])->filter()->implode(' | ')) ?: null;

            $locks = $this->lockedRangesFor(
                $department,
                Carbon::parse($dates->min(), config('schedule.timezone')),
                Carbon::parse($dates->max(), config('schedule.timezone')),
            );

            $created = collect();
            $dayOffs = collect();
            $lockSkipped = collect();

            foreach ($entries as $entry) {
                if ($blocked->has($entry['employee_id'].'|'.$entry['work_date'])) {
                    continue;
                }

                if ($department->category === Department::CATEGORY_ADMINISTRATIVE
                    && Carbon::parse($entry['work_date'], config('schedule.timezone'))->isSunday()) {
                    $lockSkipped->push($this->issue(
                        $entry,
                        $employees->get($entry['employee_id'])?->full_name ?? 'Unknown employee',
                        $entry['shift_id'] !== null ? Shift::find($entry['shift_id'])?->name : null,
                        'Administrative departments do not schedule Sundays',
                    ));

                    continue;
                }

                if ($this->isLocked($locks, $entry['work_date'])) {
                    $lockSkipped->push($this->issue(
                        $entry,
                        $employees->get($entry['employee_id'])?->full_name ?? 'Unknown employee',
                        $entry['shift_id'] !== null ? Shift::find($entry['shift_id'])?->name : null,
                        'Schedule locked for this period',
                    ));

                    continue;
                }

                if ($entry['shift_id'] === null) {
                    $dayOffs->push(ScheduleDayOff::query()->create([
                        'employee_id' => $entry['employee_id'],
                        'work_date' => $entry['work_date'],
                        'source' => 'roster_draft',
                        'notes' => $notes ?? 'Rest day set on the reviewed roster.',
                        'created_by' => $creator->id,
                    ]));

                    continue;
                }

                $created->push(ScheduleAssignment::query()->create([
                    'employee_id' => $entry['employee_id'],
                    'shift_id' => $entry['shift_id'],
                    'work_date' => $entry['work_date'],
                    'status' => 'scheduled',
                    'notes' => $notes,
                    'created_by' => $creator->id,
                ]));
            }

            $draft?->update(['status' => 'published', 'published_at' => now()]);

            return [
                'assignments' => $created,
                'day_offs' => $dayOffs,
                'skipped' => collect($evaluation['issues'])
                    ->merge($lockSkipped->map(fn (array $issue) => collect($issue)->except('key')->all())),
            ];
        });
    }

    /**
     * Save the roster board exactly as it stands so a second reviewer can pick
     * up where the first left off, instead of the draft only living in one
     * browser tab's in-memory array until the modal is closed.
     *
     * @param  Collection<int, array{employee_id: int, shift_id: int|null, work_date: string}>  $entries
     */
    public function saveDraft(
        Department $department,
        Collection $entries,
        string $startDate,
        string $endDate,
        User $user,
        ?string $notes,
        ?string $uuid = null,
        array $rules = [],
    ): RosterDraft {
        $draft = $uuid !== null
            ? RosterDraft::query()->where('uuid', $uuid)->where('status', 'open')->first()
            : null;

        if ($draft !== null) {
            $draft->update([
                'department_id' => $department->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'entries' => $entries->values()->all(),
                'rules' => $rules,
                'notes' => $notes,
                'updated_by' => $user->id,
            ]);

            return $draft->refresh();
        }

        return RosterDraft::query()->create([
            'uuid' => (string) Str::uuid(),
            'department_id' => $department->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'entries' => $entries->values()->all(),
            'rules' => $rules,
            'status' => 'open',
            'notes' => $notes,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    public function discardDraft(RosterDraft $draft): RosterDraft
    {
        $draft->update(['status' => 'discarded', 'discarded_at' => now()]);

        return $draft->refresh();
    }

    /** @return Collection<int, RosterDraft> */
    public function openDraftsFor(Department $department): Collection
    {
        return RosterDraft::query()
            ->where('department_id', $department->id)
            ->where('status', 'open')
            ->latest('updated_at')
            ->limit(10)
            ->get();
    }

    /** @return Collection<int, ScheduleLock> */
    private function lockedRangesFor(Department $department, Carbon $start, Carbon $end): Collection
    {
        return ScheduleLock::query()
            ->where('department_id', $department->id)
            ->whereNull('unlocked_at')
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get();
    }

    /** @param  Collection<int, ScheduleLock>  $locks */
    private function isLocked(Collection $locks, string $workDate): bool
    {
        return $locks->contains(fn (ScheduleLock $lock) => $workDate >= $lock->start_date->toDateString()
            && $workDate <= $lock->end_date->toDateString());
    }

    /**
     * @return array{assignments: Collection, leaves: Collection, dayOffs: Collection}
     */
    private function contextFor(array $employeeIds, Carbon $start, Carbon $end): array
    {
        // Extra margin beyond the week boundary so the consecutive-workday
        // check can see a streak that started before this range.
        $streakMargin = max(1, (int) config('schedule.max_consecutive_workdays'));

        return [
            'assignments' => ScheduleAssignment::query()
                ->with('shift')
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'scheduled')
                ->whereBetween('work_date', [
                    $start->copy()->startOfWeek()->subDay()->subDays($streakMargin)->toDateString(),
                    $end->copy()->endOfWeek()->addDay()->addDays($streakMargin)->toDateString(),
                ])
                ->get()
                ->groupBy('employee_id'),
            'leaves' => LeaveRequest::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())
                ->get()
                ->groupBy('employee_id'),
            'dayOffs' => ScheduleDayOff::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
                ->get()
                ->groupBy('employee_id'),
        ];
    }

    /**
     * @param  array{employee_id: int, shift_id: int|null, work_date: string}  $entry
     * @return array<string, mixed>
     */
    private function issue(array $entry, string $employeeName, ?string $shiftName, string $reason): array
    {
        return [
            'key' => $this->entryKey($entry),
            'employee_id' => $entry['employee_id'],
            'employee' => $employeeName,
            'work_date' => $entry['work_date'],
            'shift' => $shiftName,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array{employee_id: int, shift_id: int|null, work_date: string}  $entry
     */
    private function entryKey(array $entry): string
    {
        return $entry['employee_id'].'|'.$entry['work_date'].'|'.($entry['shift_id'] ?? 'off');
    }
}
