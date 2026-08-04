<?php

namespace App\Services\Scheduling;

use App\Models\Department;
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
     * @return array<string, mixed>
     */
    public function evaluate(Department $department, Collection $entries, string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate, config('schedule.timezone'))->startOfDay();
        $end = Carbon::parse($endDate, config('schedule.timezone'))->startOfDay();
        $dates = collect(CarbonPeriod::create($start, $end))
            ->map(fn ($date) => Carbon::instance($date)->timezone(config('schedule.timezone'))->startOfDay());

        $employees = Employee::query()
            ->with('position')
            ->whereKey($entries->pluck('employee_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $shifts = Shift::query()
            ->whereKey($entries->pluck('shift_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        // Every active shift is shown, so a shift nobody has been placed on still
        // appears with its requirement rather than vanishing from the roster.
        $allShifts = Shift::query()->where('is_active', true)->orderBy('start_time')->get();
        $requirements = $this->staffingRequirements->forShifts($department, $allShifts);

        $context = $this->contextFor($employees->keys()->all(), $start, $end);
        $issues = collect();
        $seniorRank = ScheduleService::DEFAULT_SENIOR_RANK_THRESHOLD;

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

        $days = $dates->map(function (Carbon $date) use ($working, $dayOffEntries, $employees, $allShifts, $requirements, $seniorRank, $blocked) {
            $dateString = $date->toDateString();

            $shiftRows = $allShifts->map(function (Shift $shift) use ($working, $employees, $requirements, $seniorRank, $blocked, $dateString) {
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
            'summary' => [
                'assignments' => $working->count() - $issues->count(),
                'day_offs' => $dayOffEntries->count(),
                'blocked' => $issues->count(),
                'shifts_short' => $days->sum(fn (array $day) => collect($day['shifts'])->reject(fn (array $row) => $row['meets_requirement'])->count()),
            ],
            'coverage_standard' => $this->staffingRequirements->derivationSummary($department),
        ];
    }

    /**
     * Write the roster exactly as supplied. Entries that cannot be scheduled are
     * reported back rather than silently dropped or silently replaced.
     *
     * @param  Collection<int, array{employee_id: int, shift_id: int|null, work_date: string}>  $entries
     * @return array<string, mixed>
     */
    public function publish(Department $department, Collection $entries, User $creator, ?string $notes = null): array
    {
        return DB::transaction(function () use ($department, $entries, $creator, $notes) {
            $employeeIds = $entries->pluck('employee_id')->unique()->all();
            Employee::query()->whereKey($employeeIds)->lockForUpdate()->get();

            $dates = $entries->pluck('work_date');
            $evaluation = $this->evaluate($department, $entries, $dates->min(), $dates->max());
            $blocked = collect($evaluation['issues'])
                ->map(fn (array $issue) => $issue['employee_id'].'|'.$issue['work_date'])
                ->flip();

            $created = collect();
            $dayOffs = collect();

            foreach ($entries as $entry) {
                if ($blocked->has($entry['employee_id'].'|'.$entry['work_date'])) {
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

            return [
                'assignments' => $created,
                'day_offs' => $dayOffs,
                'skipped' => collect($evaluation['issues']),
            ];
        });
    }

    /**
     * @return array{assignments: Collection, leaves: Collection, dayOffs: Collection}
     */
    private function contextFor(array $employeeIds, Carbon $start, Carbon $end): array
    {
        return [
            'assignments' => ScheduleAssignment::query()
                ->with('shift')
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'scheduled')
                ->whereBetween('work_date', [
                    $start->copy()->startOfWeek()->subDay()->toDateString(),
                    $end->copy()->endOfWeek()->addDay()->toDateString(),
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
