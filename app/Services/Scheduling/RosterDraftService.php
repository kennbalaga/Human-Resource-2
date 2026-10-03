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
use App\Services\Burnout\BurnoutProtection;
use App\Services\ScheduleService;
use App\Support\ScheduleWeek;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
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
    /**
     * Why an entry was held back because the department already has the cover
     * it needs on that shift and date. Named rather than written twice, since
     * publish() decides what to tell the reviewer by matching on it.
     */
    public const REASON_ALREADY_ROSTERED = 'Shift already rostered for this date';

    public const REASON_REST_DAY_RECORDED = 'Rest day already recorded';

    /**
     * Why a shift was refused on a date the department's own calendar keeps as a
     * rest day. Named for the same reason as the two above: publish() matches on
     * it to tell the reviewer what was held back.
     */
    public const REASON_STANDING_REST_DAY = 'Administrative departments do not schedule Sundays';

    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly StaffingRequirementService $staffingRequirements,
        private readonly BurnoutProtection $burnoutProtection,
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
            ->values();

        // The dates this department's own calendar keeps as rest days. An
        // administrative office runs Monday-Saturday, so its Sundays stay on
        // the board -- but as the unit's rest day, not as a column of shifts
        // nobody staffs and publishing is then gated on. Empty for a clinical
        // or support unit, which rotates its rest days and has no standing one.
        $standingRestDates = $dates->filter(fn (Carbon $date) => $department->isStandingRestDay($date))->values();

        $employees = Employee::query()
            ->with('position')
            ->whereKey($entries->pluck('employee_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $shifts = Shift::query()
            ->whereKey($entries->pluck('shift_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        $relevantShifts = $this->relevantShiftsFor($rules);
        $requirements = $this->staffingRequirements->forShifts($department, $relevantShifts);

        // Whether a shift is adequately covered is judged against the unit's own
        // standard, never against a ceiling typed for one run — the roster form
        // answers how many are too many, not how few are enough. The charge-cover
        // figure is still the reviewer's to set for this evaluation.
        if (isset($rules['minimum_senior_per_shift'])) {
            $requirements = $requirements->map(fn (array $requirement): array => [
                'staff' => (int) $requirement['staff'],
                'senior' => (int) $rules['minimum_senior_per_shift'],
                'source' => $requirement['source'],
            ]);
        }

        // Who a previously published roster already puts on these shifts and
        // dates. Cover is a property of the department's schedule, not of the
        // board being reviewed: without reading it back, a second run over an
        // already-staffed period sees an empty week and quietly stacks a whole
        // extra team on top of the one already on duty.
        $rostered = $this->publishedCoverageFor($department, $relevantShifts, $start, $end);

        $context = $this->contextFor($employees->keys()->all(), $start, $end);
        $issues = collect();
        $seniorRank = (int) ($rules['senior_rank_threshold'] ?? ScheduleService::DEFAULT_SENIOR_RANK_THRESHOLD);

        // Burnout risk is shown beside every name on the board, and the
        // high-risk employees are held to their protected limits below.
        $burnout = $this->burnoutProtection->assessments($employees->keys()->all());
        $protected = $this->burnoutProtection->protectedAmong($burnout);
        $burnoutWarnings = collect();

        $working = $entries->filter(fn (array $entry) => $entry['shift_id'] !== null);

        // A standing rest day belongs to the roster whether or not the reviewer
        // placed it: an administrative office's Sunday is a rest day for
        // everyone on the board, however the board was filled -- by the
        // assistant, by a bulk fill, or by hand. Derived here from the one
        // department rule so every path shows, and publishes, the same thing,
        // instead of each fill path having to remember the rule for itself.
        $dayOffEntries = $entries->filter(fn (array $entry) => $entry['shift_id'] === null)
            ->merge($this->standingRestDayEntries(
                $employees->keys(),
                $standingRestDates,
                $entries,
                $context['dayOffs'],
            ))
            ->values();

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

            // Reported here rather than only refused at publish time, so the
            // reviewer sees on the board that this unit does not staff the day
            // at all -- the rest day below takes it instead.
            if ($department->isStandingRestDay($date)) {
                $issues->push($this->issue($entry, $employee->full_name, $shift->name, self::REASON_STANDING_REST_DAY));

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

            // Held back rather than added: an earlier roster run already staffs
            // this shift on this date to the unit's standard, and a second run
            // over the same period would put a whole second team beside the
            // first.
            //
            // Only cover a previous *roster* put there counts here, which is
            // what separates the two ways a date can already be busy. Someone
            // added by hand to a single day is a deliberate one-off, and a
            // roster still has to be able to staff the shift around them;
            // a period the generator has already published is the duplicate
            // this is here to refuse.
            $required = (int) ($requirements->get($shift->id)['staff'] ?? StaffingRequirementService::FALLBACK_MINIMUM_STAFF);
            $rosteredHere = $rostered->get($shift->id.'|'.$date->toDateString(), collect());

            if ($rosteredHere->where('created_via', 'bulk_fill')->count() >= $required) {
                $issues->push($this->issue($entry, $employee->full_name, $shift->name, self::REASON_ALREADY_ROSTERED));

                continue;
            }

            // Tier B, like the night streak below: the placement stays on the
            // board, but going past a high-risk employee's protected limits
            // needs a reason on record before the roster can be published.
            if ($protected->has($employee->id)) {
                $burnoutReason = $this->burnoutProtection->blockReason(
                    $shift,
                    $date,
                    $context['assignments']->get($employee->id, collect())->merge($alreadyPlaced),
                );

                if ($burnoutReason !== null) {
                    $burnoutWarnings->push([
                        'employee_id' => $employee->id,
                        'employee' => $employee->full_name,
                        'work_date' => $date->toDateString(),
                        'shift' => $shift->name,
                        'reason' => $burnoutReason,
                    ]);
                }
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

        // A rest day is one row per employee per date by construction, so a
        // re-run proposing one that is already on record would fail against the
        // table's own unique key. Reported here instead, the way every other
        // held-back entry is.
        $dayOffIssues = 0;
        foreach ($dayOffEntries as $entry) {
            $recorded = $context['dayOffs']->get($entry['employee_id'], collect())
                ->contains(fn (ScheduleDayOff $dayOff) => $dayOff->work_date->toDateString() === $entry['work_date']);

            if (! $recorded) {
                continue;
            }

            $issues->push($this->issue(
                $entry,
                $employees->get($entry['employee_id'])?->full_name ?? 'Unknown employee',
                null,
                self::REASON_REST_DAY_RECORDED,
            ));
            $dayOffIssues++;
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

        $days = $dates->map(function (Carbon $date) use ($department, $working, $dayOffEntries, $employees, $relevantShifts, $requirements, $seniorRank, $blocked, $rostered, $burnout) {
            $dateString = $date->toDateString();
            $isStandingRestDay = $department->isStandingRestDay($date);

            $shiftRows = $isStandingRestDay ? collect() : $relevantShifts->map(function (Shift $shift) use ($working, $employees, $requirements, $seniorRank, $blocked, $dateString, $rostered, $burnout) {
                $assigned = $working
                    ->filter(fn (array $entry) => $entry['work_date'] === $dateString && (int) $entry['shift_id'] === $shift->id)
                    ->map(function (array $entry) use ($employees, $seniorRank, $blocked, $burnout) {
                        $employee = $employees->get($entry['employee_id']);
                        $rank = (int) ($employee?->position?->seniority_rank ?? 1);

                        return [
                            'employee_id' => $entry['employee_id'],
                            'name' => $employee?->full_name ?? 'Unknown',
                            'employee_number' => $employee?->employee_number,
                            'position' => $employee?->position?->title,
                            'is_senior' => $rank >= $seniorRank,
                            'blocked' => $blocked->has($this->entryKey($entry)),
                            'burnout_level' => $burnout->get($entry['employee_id'])?->level,
                        ];
                    })
                    ->values();

                $requirement = $requirements->get($shift->id, ['staff' => 1, 'senior' => 0, 'source' => 'default minimum']);
                $placeable = $assigned->reject(fn (array $row) => $row['blocked']);

                // Anyone a previous roster already put on this shift counts
                // toward the date's cover, so the board grades it as the ward
                // will actually be staffed rather than as this run alone would
                // leave it — which is also what keeps the publish-time coverage
                // gate from calling an already-covered date short.
                $alreadyRostered = $rostered->get($shift->id.'|'.$dateString, collect());
                $onDuty = $placeable->count() + $alreadyRostered->count();
                $seniorsOnDuty = $placeable->where('is_senior', true)->count()
                    + $alreadyRostered
                        ->filter(fn (ScheduleAssignment $assignment) => (int) ($assignment->employee?->position?->seniority_rank ?? 1) >= $seniorRank)
                        ->count();

                return [
                    'shift_id' => $shift->id,
                    'shift' => $shift->name,
                    'time' => $shift->formatted_time ?? null,
                    'is_night' => $shift->is_night_shift,
                    'assigned' => $assigned->values()->all(),
                    'already_rostered' => $alreadyRostered->count(),
                    // The subset of that cover a previous *roster run* published,
                    // which is the only kind that means "this period has already
                    // been generated". Someone added to one day by hand, or a
                    // recurring series, still counts towards cover above but must
                    // not read as a duplicate run — so the two are reported apart
                    // rather than the gate having to guess from one number.
                    'rostered_by_run' => $alreadyRostered->where('created_via', 'bulk_fill')->count(),
                    'count' => $onDuty,
                    'required' => $requirement['staff'],
                    'senior_count' => $seniorsOnDuty,
                    'senior_required' => $requirement['senior'],
                    'requirement_source' => $requirement['source'],
                    'meets_requirement' => $onDuty >= $requirement['staff']
                        && $seniorsOnDuty >= $requirement['senior'],
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
                // No shift rows at all on a standing rest day, which is also
                // what keeps the publish-time coverage gate off a date the unit
                // never staffs: an empty set of shifts is vacuously covered.
                'is_standing_rest_day' => $isStandingRestDay,
                'shifts' => $shiftRows->all(),
                'day_offs' => $offToday->all(),
                'fully_covered' => $shiftRows->every(fn (array $row) => $row['meets_requirement']),
            ];
        })->values();

        return [
            'days' => $days->all(),
            'issues' => $issues->map(fn (array $issue) => collect($issue)->except('key')->all())->values()->all(),
            'night_streak_warnings' => $nightStreakWarnings->values()->all(),
            'burnout_warnings' => $burnoutWarnings->values()->all(),
            'summary' => [
                'assignments' => $working->count() - ($issues->count() - $dayOffIssues),
                'day_offs' => $dayOffEntries->count() - $dayOffIssues,
                'blocked' => $issues->count(),
                'shifts_short' => $days->sum(fn (array $day) => collect($day['shifts'])->reject(fn (array $row) => $row['meets_requirement'])->count()),
                'night_streak_warnings' => $nightStreakWarnings->count(),
                'burnout_warnings' => $burnoutWarnings->count(),
                // Everyone on this roster at high burnout risk, whether or not
                // any placement takes them past their limits.
                'burnout_protected' => $protected->keys()->intersect($placed->keys())->count(),
                // The period's own duplicate state, counted from the cover on
                // record rather than from the issue list above — the rotation
                // assistant drops already-covered placements before they ever
                // become entries, so on that path no entry is ever *blocked* and
                // an issue-derived count would read zero on exactly the run this
                // is meant to stop.
                'shifts_already_rostered' => $days->sum(fn (array $day) => collect($day['shifts'])->where('rostered_by_run', '>', 0)->count()),
                'days_already_rostered' => $days->filter(fn (array $day) => collect($day['shifts'])->sum('rostered_by_run') > 0)->count(),
                'assignments_already_rostered' => $days->sum(fn (array $day) => collect($day['shifts'])->sum('rostered_by_run')),
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
            'compliance' => $this->laborCompliance($placed, $issues, $context['assignments'], $rules),
        ];
    }

    /**
     * The Step 4 labor-compliance read-back: one row per Labor Code article the
     * roster touches, judged on what publishing would actually write — the
     * placements that survived evaluation plus what is already on record for
     * the same employees. Placements the evaluation held back are counted
     * against the rule that stopped them, so the reviewer can see the rule was
     * enforced rather than merely assumed.
     *
     * @param  Collection<int, Collection<int, ScheduleAssignment>>  $placed
     * @param  Collection<int, array<string, mixed>>  $issues
     * @param  Collection<int, Collection<int, ScheduleAssignment>>  $existing
     * @param  array<string, mixed>  $rules
     * @return array<int, array{rule: string, article: string, state: string, detail: string}>
     */
    private function laborCompliance(Collection $placed, Collection $issues, Collection $existing, array $rules): array
    {
        $assignments = $placed->flatten(1);
        $shiftsUsed = $assignments->map(fn (ScheduleAssignment $assignment) => $assignment->shift)->filter()->unique('id')->values();
        $heldBack = fn (array $reasons): int => $issues->filter(fn (array $issue) => in_array($issue['reason'], $reasons, true))->count();
        $heldNote = fn (int $count): string => $count > 0 ? ' '.$count.' placement'.($count === 1 ? ' was' : 's were').' held back for breaking this rule.' : '';

        $longShifts = $shiftsUsed->filter(fn (Shift $shift) => $shift->duration_minutes > 8 * 60);
        $shortMeals = $shiftsUsed->filter(fn (Shift $shift) => (int) $shift->break_minutes < 60);
        $nightMinutes = $assignments->sum(fn (ScheduleAssignment $assignment) => $this->scheduleService->nightDifferentialMinutes($assignment->shift, $assignment->work_date->toDateString()));

        // Paid minutes per employee per scheduling week, counting both this
        // roster and what is already published for the same people.
        $weeklyLimit = (int) ($rules['max_hours_per_week'] ?? config('schedule.compliance.max_hours_per_week'));
        $overLimit = $placed->map(function (Collection $mine, int $employeeId) use ($existing, $weeklyLimit): int {
            return $mine->concat($existing->get($employeeId, collect()))
                ->filter(fn (ScheduleAssignment $assignment) => $assignment->shift !== null)
                ->unique(fn (ScheduleAssignment $assignment) => $assignment->work_date->toDateString().'|'.$assignment->shift_id)
                ->groupBy(fn (ScheduleAssignment $assignment) => ScheduleWeek::start($assignment->work_date)->toDateString())
                ->filter(fn (Collection $week) => $week->sum(fn (ScheduleAssignment $assignment) => $assignment->shift->duration_minutes) > $weeklyLimit * 60)
                ->count();
        })->sum();
        $overtimeAllowed = (bool) ($rules['overtime_allowed'] ?? false);

        return [
            [
                'rule' => 'Normal hours of work',
                'article' => 'Labor Code Art. 83',
                'state' => $longShifts->isEmpty() ? 'ok' : 'review',
                'detail' => $longShifts->isEmpty()
                    ? 'Every shift on this roster is at most 8 paid hours a day.'
                    : $longShifts->pluck('name')->join(', ').' '.($longShifts->count() === 1 ? 'runs' : 'run').' past 8 paid hours; the extra time is overtime.',
            ],
            [
                'rule' => 'Meal period',
                'article' => 'Labor Code Art. 85',
                'state' => $shortMeals->isEmpty() ? 'ok' : 'review',
                'detail' => $shortMeals->isEmpty()
                    ? 'Every shift includes an unpaid meal break of at least 60 minutes.'
                    : $shortMeals->pluck('name')->join(', ').' '.($shortMeals->count() === 1 ? 'has' : 'have').' a meal break under 60 minutes. Check that the shorter break is allowed and paid.',
            ],
            [
                'rule' => 'Night-shift differential',
                'article' => 'Labor Code Art. 86',
                'state' => 'ok',
                'detail' => $nightMinutes > 0
                    ? round($nightMinutes / 60, 1).' hours fall between 10:00 PM and 6:00 AM and are recorded for the night-shift differential.'
                    : 'No hours on this roster fall between 10:00 PM and 6:00 AM.',
            ],
            [
                'rule' => 'Overtime',
                'article' => 'Labor Code Art. 87',
                'state' => $overLimit > 0 ? 'review' : 'ok',
                'detail' => ($overLimit > 0
                    ? $overLimit.' employee-week'.($overLimit === 1 ? '' : 's').' go past '.$weeklyLimit.' paid hours'.($overtimeAllowed ? ' under the overtime allowance on Step 2; those hours are paid as overtime.' : '.')
                    : 'Nobody is scheduled past '.$weeklyLimit.' paid hours in a week.').$heldNote($heldBack(['Maximum weekly hours exceeded'])),
            ],
            [
                'rule' => 'Weekly rest day',
                'article' => 'Labor Code Art. 91',
                'state' => 'ok',
                'detail' => 'Everyone keeps at least 24 consecutive hours of rest after six workdays.'.$heldNote($heldBack([
                    'Weekly rest day not met (Labor Code Art. 91)',
                    'Days-off rule would be exceeded',
                    'Maximum consecutive workdays exceeded',
                ])),
            ],
            [
                'rule' => 'Hospital HR policy',
                'article' => 'Hospital policy',
                'state' => 'pending',
                'detail' => 'The hospital’s own scheduling rules are not set up in the system yet, so they are not checked here. Review them by hand before approving.',
            ],
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
            $shifts = Shift::query()->whereKey($entries->pluck('shift_id')->filter()->unique()->all())->get()->keyBy('id');

            $dates = $entries->pluck('work_date');
            $evaluation = $this->evaluate($department, $entries, $dates->min(), $dates->max(), $rules);
            $blocked = collect($evaluation['issues'])
                ->map(fn (array $issue) => $issue['employee_id'].'|'.$issue['work_date'])
                ->flip();

            // The period the reviewer actually chose, which is wider than the
            // entries whenever a date carries none -- and a standing rest day
            // is exactly such a date, since no fill path ever places a shift on
            // one. Read from the entries only as a fallback, and deliberately
            // not used for the evaluation above: grading a date nobody was
            // placed on would read as a shift left short and refuse the publish.
            $periodStart = Carbon::parse($rules['start_date'] ?? $dates->min(), config('schedule.timezone'))->startOfDay();
            $periodEnd = Carbon::parse($rules['end_date'] ?? $dates->max(), config('schedule.timezone'))->startOfDay();

            // Hard block: any part of this period that a previous roster run
            // already published stops the whole publish, rather than the run
            // going ahead with the overlapping days quietly held back.
            //
            // Read straight from the cover on record over the period the
            // reviewer actually chose, for two reasons the evaluation above
            // cannot serve. The rotation assistant drops already-covered
            // placements before they become entries, so nothing is *blocked* on
            // that path and an issue-derived count reads zero on precisely the
            // re-run this exists to refuse; and the entries that survive span
            // only the free days, so a window derived from them would step over
            // the rostered ones entirely.
            $this->assertPeriodNotAlreadyRostered($department, $rules, $dates);

            // A rest day already on record cannot be written twice either — the
            // table's own unique key would refuse it — and it can outlive the
            // assignments above, so it is still checked in its own right.
            $duplicates = collect($evaluation['issues'])
                ->whereIn('reason', [self::REASON_ALREADY_ROSTERED, self::REASON_REST_DAY_RECORDED])
                ->count();
            $writable = $entries
                ->reject(fn (array $entry) => $blocked->has($entry['employee_id'].'|'.$entry['work_date']))
                ->count();

            if ($writable === 0 && $duplicates > 0) {
                throw ValidationException::withMessages([
                    'entries' => 'This department is already rostered for these dates, so publishing again would duplicate the schedule. Change the period, or remove the existing assignments first.',
                ]);
            }

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

            // Tier B, the same shape: an employee at high burnout risk placed
            // past their protected limits is publishable only with a reason.
            if (($evaluation['summary']['burnout_warnings'] ?? 0) > 0 && trim((string) ($rules['burnout_justification'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'burnout_justification' => 'This roster schedules one or more employees at high burnout risk beyond their protected limits. Enter a justification before publishing.',
                ]);
            }

            $auditTrail = collect([
                ($rules['overtime_allowed'] ?? false) ? 'Overtime justification: '.trim((string) ($rules['overtime_justification'] ?? '')) : null,
                ($evaluation['summary']['night_streak_warnings'] ?? 0) > 0 ? 'Consecutive night shift justification: '.trim((string) ($rules['night_streak_justification'] ?? '')) : null,
                ($evaluation['summary']['burnout_warnings'] ?? 0) > 0 ? 'Burnout risk justification: '.trim((string) ($rules['burnout_justification'] ?? '')) : null,
            ])->filter()->implode(' — ');
            $notes = trim(collect([$notes, $auditTrail])->filter()->implode(' | ')) ?: null;

            // Added only now that every publish gate above has had its say, and
            // deliberately not before: the gates judge what the reviewer
            // submitted, and a derived rest day counted among the writable
            // entries would mask the "already rostered" refusal on a re-run of
            // a period that is wholly duplicate.
            //
            // The standing rest days are the unit's own calendar rather than a
            // roster decision, so they land however the board was filled -- by
            // the assistant, by a bulk fill, or by hand. Derived from the same
            // department rule the board was evaluated under, so the write and
            // the board agree by construction.
            $entries = $entries->merge($this->standingRestDayEntries(
                $employees->keys(),
                collect(CarbonPeriod::create($periodStart, $periodEnd))
                    ->map(fn ($date) => Carbon::instance($date)->timezone(config('schedule.timezone'))->startOfDay())
                    ->filter(fn (Carbon $date) => $department->isStandingRestDay($date))
                    ->values(),
                $entries,
            ));

            // Over the chosen period, not the entries' own extent: a standing
            // rest day can fall on a date no entry reaches, and it must still
            // be held back when that date is locked.
            $locks = $this->lockedRangesFor($department, $periodStart, $periodEnd);

            $created = collect();
            $dayOffs = collect();
            $lockSkipped = collect();

            RosterWriteContext::allow($creator, function () use (
                $entries, $blocked, $department, $employees, $shifts, $locks, $notes, $creator,
                &$created, &$dayOffs, &$lockSkipped,
            ): void {
                foreach ($entries as $entry) {
                    if ($blocked->has($entry['employee_id'].'|'.$entry['work_date'])) {
                        continue;
                    }

                    // A shift is refused on the unit's standing rest day; a rest
                    // day on it is exactly what should be written, which is what
                    // this guard used to swallow along with the shifts.
                    if ($entry['shift_id'] !== null
                        && $department->isStandingRestDay(Carbon::parse($entry['work_date'], config('schedule.timezone')))) {
                        $lockSkipped->push($this->issue(
                            $entry,
                            $employees->get($entry['employee_id'])?->full_name ?? 'Unknown employee',
                            $shifts->get($entry['shift_id'])?->name,
                            self::REASON_STANDING_REST_DAY,
                        ));

                        continue;
                    }

                    if ($this->isLocked($locks, $entry['work_date'])) {
                        $lockSkipped->push($this->issue(
                            $entry,
                            $employees->get($entry['employee_id'])?->full_name ?? 'Unknown employee',
                            $entry['shift_id'] !== null ? $shifts->get($entry['shift_id'])?->name : null,
                            'Schedule locked for this period',
                        ));

                        continue;
                    }

                    if ($entry['shift_id'] === null) {
                        // Derived here as well as flagged, so a rest day the
                        // assistant placed on the unit's standing rest day is
                        // recorded as what it is rather than as a roster
                        // decision that happened to land on a Sunday.
                        $standing = ($entry['standing'] ?? false)
                            || $department->isStandingRestDay(Carbon::parse($entry['work_date'], config('schedule.timezone')));
                        // firstOrCreate, not create: a standing rest day is
                        // derived rather than placed, so one already on record
                        // is a no-op here instead of an entry nobody chose
                        // failing against the table's unique key. What the
                        // reviewer did place is still reported as a duplicate
                        // by evaluate() and never reaches this line.
                        $dayOff = ScheduleDayOff::query()->firstOrCreate(
                            [
                                'employee_id' => $entry['employee_id'],
                                'work_date' => $entry['work_date'],
                            ],
                            [
                                'source' => $standing ? 'standing_rest_day' : 'roster_draft',
                                'notes' => $standing
                                    ? $department->name.' is closed on Sundays.'
                                    : ($notes ?? 'Rest day set on the reviewed roster.'),
                                'created_by' => $creator->id,
                            ],
                        );

                        if ($dayOff->wasRecentlyCreated) {
                            $dayOffs->push($dayOff);
                        }

                        continue;
                    }

                    $created->push(ScheduleAssignment::query()->create([
                        'employee_id' => $entry['employee_id'],
                        'shift_id' => $entry['shift_id'],
                        'work_date' => $entry['work_date'],
                        'status' => 'scheduled',
                        'notes' => $notes,
                        'created_by' => $creator->id,
                        'created_via' => 'bulk_fill',
                    ]));
                }
            });

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

    /**
     * Refuse the publish outright when a previous roster run already covers any
     * part of the period being published.
     *
     * The period is the one the reviewer chose on Step 2 whenever the caller
     * passes it, falling back to the span of the entries themselves. That
     * distinction is the whole point: a re-run over a half-rostered period
     * arrives here holding only the entries for the free days, and a window
     * derived from those would look at exactly the dates that are not the
     * problem.
     *
     * @param  array<string, mixed>  $rules
     * @param  Collection<int, string>  $dates
     */
    private function assertPeriodNotAlreadyRostered(Department $department, array $rules, Collection $dates): void
    {
        $from = $rules['start_date'] ?? $dates->min();
        $to = $rules['end_date'] ?? $dates->max();

        if ($from === null || $to === null) {
            return;
        }

        $rostered = $this->publishedCoverageFor(
            $department,
            $this->relevantShiftsFor($rules),
            Carbon::parse($from, config('schedule.timezone'))->startOfDay(),
            Carbon::parse($to, config('schedule.timezone'))->startOfDay(),
        )
            ->map(fn (Collection $assignments) => $assignments->where('created_via', 'bulk_fill'))
            ->reject(fn (Collection $assignments) => $assignments->isEmpty());

        if ($rostered->isEmpty()) {
            return;
        }

        $existing = $rostered->sum(fn (Collection $assignments) => $assignments->count());
        $days = $rostered->keys()
            ->map(fn (string $key) => explode('|', $key)[1])
            ->unique()
            ->count();

        throw ValidationException::withMessages([
            'entries' => "This period already has a published roster — {$existing} ".
                str('assignment')->plural($existing)." across {$days} ".
                str('day')->plural($days).
                '. Publishing again would schedule this department twice over the same dates. Remove those assignments first, or choose a period that is not yet rostered.',
        ]);
    }

    /**
     * The shifts this roster run is answerable for.
     *
     * Every shift the run was built for is graded, so one nobody has been placed
     * on yet still shows its requirement rather than vanishing from the board —
     * but a shift outside that selection (Administrative, say, when this run is
     * Night-only) is not this roster's concern. Shared with the publish-time
     * duplicate gate so the two cannot come to different answers about which
     * shifts the period covers.
     *
     * @param  array<string, mixed>  $rules
     * @return Collection<int, Shift>
     */
    private function relevantShiftsFor(array $rules): Collection
    {
        $allShifts = Shift::query()->where('is_active', true)->orderBy('start_time')->get();
        $relevantShiftIds = collect($rules['shift_ids'] ?? [])
            ->push($rules['shift_id'] ?? null)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        return $relevantShiftIds->isNotEmpty()
            ? $allShifts->whereIn('id', $relevantShiftIds->all())->values()
            : $allShifts;
    }

    /**
     * This department's already-published assignments for the given shifts and
     * dates, grouped by "<shift id>|<date>".
     *
     * Scoped by the employee's department rather than by the names on the board:
     * a re-run typically rotates a different set of people onto the same days,
     * so asking only about the employees in front of us would report the week as
     * free and let the duplicate through.
     *
     * @param  Collection<int, Shift>  $shifts
     * @return Collection<string, Collection<int, ScheduleAssignment>>
     */
    private function publishedCoverageFor(Department $department, Collection $shifts, Carbon $start, Carbon $end): Collection
    {
        if ($shifts->isEmpty()) {
            return collect();
        }

        return ScheduleAssignment::query()
            ->with('employee.position')
            ->where('status', 'scheduled')
            ->whereIn('shift_id', $shifts->pluck('id')->all())
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('employee', fn (Builder $query) => $query->where('department_id', $department->id))
            ->get()
            ->groupBy(fn (ScheduleAssignment $assignment) => $assignment->shift_id.'|'.$assignment->work_date->toDateString());
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
                    ScheduleWeek::start($start)->subDay()->subDays($streakMargin)->toDateString(),
                    ScheduleWeek::end($end)->addDay()->addDays($streakMargin)->toDateString(),
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
            // Compared as calendar dates, not as strings: schedule_day_offs
            // casts work_date as a plain date and so stores it with a
            // 00:00:00 time, which sorts after the bare end-of-range date and
            // would drop every rest day falling on the last day of the period.
            'dayOffs' => ScheduleDayOff::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereDate('work_date', '>=', $start->toDateString())
                ->whereDate('work_date', '<=', $end->toDateString())
                ->get()
                ->groupBy('employee_id'),
        ];
    }

    /**
     * The rest days a department's own calendar sets over this period, for
     * everyone on the board.
     *
     * A date already carrying an entry for that employee is left alone: the
     * reviewer put something there, and if it is a shift on a standing rest day
     * the working loop refuses it by name rather than a second entry quietly
     * appearing beside it. One already on record is left out too -- nobody
     * placed it, and the table's unique key would refuse it anyway.
     *
     * @param  Collection<int, int>  $employeeIds
     * @param  Collection<int, Carbon>  $restDates
     * @param  Collection<int, array{employee_id: int, shift_id: int|null, work_date: string}>  $placed
     * @param  Collection<int, Collection<int, ScheduleDayOff>>  $recorded
     * @return Collection<int, array{employee_id: int, shift_id: null, work_date: string, standing: true}>
     */
    private function standingRestDayEntries(
        Collection $employeeIds,
        Collection $restDates,
        Collection $placed,
        Collection $recorded = new Collection,
    ): Collection {
        if ($restDates->isEmpty() || $employeeIds->isEmpty()) {
            return collect();
        }

        $taken = $placed->map(fn (array $entry) => $entry['employee_id'].'|'.$entry['work_date'])->flip();

        return $employeeIds
            ->crossJoin($restDates->map(fn (Carbon $date) => $date->toDateString()))
            ->map(fn (array $pair) => [
                'employee_id' => (int) $pair[0],
                'shift_id' => null,
                'work_date' => $pair[1],
                'standing' => true,
            ])
            ->reject(fn (array $entry) => $taken->has($entry['employee_id'].'|'.$entry['work_date']))
            ->reject(fn (array $entry) => $recorded->get($entry['employee_id'], collect())
                ->contains(fn (ScheduleDayOff $dayOff) => $dayOff->work_date->toDateString() === $entry['work_date']))
            ->values();
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
