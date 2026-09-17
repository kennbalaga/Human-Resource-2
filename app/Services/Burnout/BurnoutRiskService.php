<?php

namespace App\Services\Burnout;

use App\Models\BurnoutRiskSnapshot;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\Position;
use App\Models\User;
use App\Services\ReferenceDataCache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Today's burnout risk, one assessment per active employee, and its history.
 *
 * Every screen that shows the indicator -- the staff dashboard, the analytics
 * tab, the scheduling assistants -- reads it through `current()`, so the three
 * cannot disagree about the same person on the same day. `current()` answers
 * from the stored snapshot when one exists and makes it on the spot when it
 * does not, which is why the nightly `burnout:snapshot` run is a warm-up rather
 * than a dependency: a machine that never runs the scheduler still shows the
 * figure, the first viewer of the day just waits for it.
 *
 * This is a non-medical workload and rest indicator. It does not diagnose
 * burnout, and nothing on it is grounds for a disciplinary or performance
 * decision. See docs/BURNOUT_RISK.md.
 */
class BurnoutRiskService
{
    public const DISCLAIMER = 'A non-medical workload and rest indicator built from attendance, roster and leave records. It does not diagnose burnout and must not be used for disciplinary or performance decisions.';

    /** How many employees one assessment pass reads at a time. */
    private const CHUNK = 200;

    public function __construct(
        private readonly BurnoutMetricsCollector $collector,
        private readonly BurnoutRiskCalculator $calculator,
        private readonly ReferenceDataCache $reference,
    ) {}

    /** Today as a plain calendar date in the scheduling timezone. */
    public function today(): Carbon
    {
        $timezone = config('schedule.timezone');

        return Carbon::parse(Carbon::now($timezone)->toDateString(), $timezone)->startOfDay();
    }

    /**
     * The people the indicator is kept for: active, and not filed away.
     *
     * @return Builder<Employee>
     */
    public function assessableEmployees(): Builder
    {
        return Employee::query()
            ->where('employment_status', 'active')
            ->notArchived();
    }

    /**
     * Assess these employees and store the result, replacing any assessment
     * already made for the same day.
     *
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, BurnoutRiskSnapshot> keyed by employee id
     */
    public function snapshot(Collection $employees, ?Carbon $asOf = null): Collection
    {
        $asOf ??= $this->today();
        $snapshots = collect();

        foreach ($employees->chunk(self::CHUNK) as $chunk) {
            $rows = [];

            foreach ($this->collector->collect($chunk->values(), $asOf) as $employeeId => $windows) {
                $current = $this->calculator->score($windows['current']);
                $previous = $windows['previous'] !== null
                    ? $this->calculator->score($windows['previous'])['score']
                    : null;

                $rows[] = [
                    'employee_id' => $employeeId,
                    'as_of_date' => $asOf->toDateString(),
                    'score' => $current['score'],
                    'previous_score' => $previous,
                    'level' => $current['level'],
                    'factors' => $current['factors'],
                ];
            }

            // upsert() writes through the query builder, so the JSON column is
            // encoded here rather than by the model's cast.
            BurnoutRiskSnapshot::query()->upsert(
                array_map(fn (array $row): array => ['factors' => json_encode($row['factors'])] + $row, $rows),
                ['employee_id', 'as_of_date'],
                ['score', 'previous_score', 'level', 'factors'],
            );

            foreach ($rows as $row) {
                $snapshots->put($row['employee_id'], new BurnoutRiskSnapshot($row));
            }
        }

        return $snapshots;
    }

    /** Assess every active employee. Returns how many were assessed. */
    public function snapshotAll(?Carbon $asOf = null): int
    {
        $count = 0;

        $this->assessableEmployees()
            ->select(['id', 'hire_date'])
            ->chunkById(self::CHUNK, function (Collection $employees) use (&$count, $asOf): void {
                $count += $this->snapshot($employees, $asOf)->count();
            });

        return $count;
    }

    /**
     * Today's assessment for each of these employees, making any that is
     * missing. Anyone no longer active is left out rather than assessed.
     *
     * @param  array<int, int|string>  $employeeIds
     * @return Collection<int, BurnoutRiskSnapshot> keyed by employee id
     */
    public function current(array $employeeIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));

        if ($ids === []) {
            return collect();
        }

        $today = $this->today();
        $stored = collect();

        foreach (array_chunk($ids, 500) as $chunk) {
            $stored = $stored->union(
                BurnoutRiskSnapshot::query()
                    ->whereIn('employee_id', $chunk)
                    ->where('as_of_date', $today->toDateString())
                    ->get()
                    ->keyBy('employee_id')
            );
        }

        $missing = array_values(array_diff($ids, $stored->keys()->all()));

        if ($missing !== []) {
            $employees = $this->assessableEmployees()
                ->whereKey($missing)
                ->get(['id', 'hire_date']);

            $stored = $stored->union($this->snapshot($employees, $today));
        }

        return $stored;
    }

    /** @return array<string, mixed>|null */
    public function forEmployee(Employee $employee): ?array
    {
        $snapshot = $this->current([$employee->id])->get($employee->id);

        return $snapshot === null ? null : $this->present($snapshot);
    }

    /**
     * Today's assessment for everyone this user supervises, one row per
     * person, in no particular order. A user who supervises nobody gets an
     * empty collection, because `visibleTo` narrows to nothing, and so does
     * anyone outside the burnout.view-workforce gate, whatever else their
     * role lets them see.
     *
     * @return Collection<int, array{employee: Employee, risk: array<string, mixed>}>
     */
    public function workforce(?User $user, ?int $departmentId = null): Collection
    {
        if ($user === null || Gate::forUser($user)->denies('burnout.view-workforce')) {
            return collect();
        }

        $employees = $this->assessableEmployees()
            ->visibleTo($user)
            ->when($departmentId, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->get(['id', 'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix', 'department_id', 'position_id', 'hire_date']);
        $this->reference->attach($employees, 'department', 'department_id', Department::class);
        $this->reference->attach($employees, 'position', 'position_id', Position::class);

        $assessments = $this->current($employees->pluck('id')->all());

        return $employees
            ->filter(fn (Employee $employee) => $assessments->has($employee->id))
            ->map(fn (Employee $employee): array => [
                'employee' => $employee,
                'risk' => $this->present($assessments->get($employee->id)),
            ])
            ->values();
    }

    /**
     * The "My workload & rest" card: an employee's own assessment, with one
     * thing they can do about it.
     *
     * Every employee gets it on whichever dashboard they land on -- staff and
     * managers alike. An indicator only managers can see is surveillance, not
     * support, and HR staff and administrators can burn out too.
     *
     * @param  float|null  $vacationDaysLeft  Pass it when already loaded; read from the balance otherwise
     * @return array<string, mixed>|null
     */
    public function card(Employee $employee, ?float $vacationDaysLeft = null): ?array
    {
        $assessment = $this->forEmployee($employee);

        if ($assessment === null) {
            return null;
        }

        $drivers = collect($assessment['drivers'])->pluck('key');
        $needsLeave = $assessment['level'] !== BurnoutRiskSnapshot::LEVEL_LOW && $drivers->contains('days_since_leave');

        if ($needsLeave && $vacationDaysLeft === null) {
            $vacationDaysLeft = (float) (LeaveBalance::query()
                ->with('leaveType')
                ->where('employee_id', $employee->id)
                ->where('year', $this->today()->year)
                ->whereHas('leaveType', fn (Builder $query) => $query->where('code', config('burnout.vacation_leave_code')))
                ->first()
                ?->available_days ?? 0);
        }

        $suggestion = match (true) {
            $assessment['level'] === BurnoutRiskSnapshot::LEVEL_LOW => [
                'text' => 'Your workload and rest look balanced. Keep taking your rest days.',
                'action' => null,
                'route' => null,
            ],
            $needsLeave && $vacationDaysLeft > 0 => [
                'text' => 'You have '.rtrim(rtrim(number_format($vacationDaysLeft, 1), '0'), '.').' vacation '.str('day')->plural((int) ceil($vacationDaysLeft)).' left. Consider filing leave for a proper break.',
                'action' => 'Request leave',
                'route' => 'leaves.index',
            ],
            $drivers->contains('night_shifts') || $drivers->contains('work_streak') || $drivers->contains('short_rest') => [
                'text' => 'Ask for day shifts or a preferred rest day so your next roster gives you more recovery time.',
                'action' => 'Set my preferences',
                'route' => 'schedule-preferences.index',
            ],
            $drivers->contains('overtime_hours') || $drivers->contains('weekly_hours') => [
                'text' => 'Your hours have been long. Talk to your supervisor about spreading the load.',
                'action' => 'View my overtime',
                'route' => 'timesheets.index',
            ],
            default => [
                'text' => 'If you are feeling worn out, talk to your supervisor or HR. Your roster can be adjusted.',
                'action' => null,
                'route' => null,
            ],
        };

        return $assessment + [
            'suggestion' => $suggestion,
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    /**
     * An assessment in the shape the screens read.
     *
     * @return array<string, mixed>
     */
    public function present(BurnoutRiskSnapshot $snapshot): array
    {
        $factors = collect($snapshot->factors)
            ->map(fn (array $factor, string $key): array => ['key' => $key] + $factor + [
                'summary' => $this->factorSummary($factor),
            ]);
        $previous = $snapshot->previous_score;

        return [
            'employee_id' => (int) $snapshot->employee_id,
            'as_of' => $snapshot->as_of_date?->toDateString(),
            'score' => round((float) $snapshot->score, 1),
            'previous_score' => $previous === null ? null : round((float) $previous, 1),
            'change' => $previous === null ? null : round((float) $snapshot->score - (float) $previous, 1),
            'level' => $snapshot->level,
            'level_label' => ucfirst($snapshot->level),
            'trend' => $this->calculator->trend((float) $snapshot->score, $previous === null ? null : (float) $previous),
            'protected' => $this->isProtectedLevel($snapshot->level),
            'factors' => $factors->values()->all(),
            // What is driving the score, largest first. A factor scoring
            // nothing is not a reason, however high its raw figure.
            'drivers' => $factors
                ->filter(fn (array $factor) => $factor['points'] > 0)
                ->sortByDesc('points')
                ->take(3)
                ->values()
                ->all(),
        ];
    }

    /** Whether this level earns the stricter scheduling limits. */
    public function isProtectedLevel(?string $level): bool
    {
        return (bool) config('burnout.protection.enabled')
            && $level !== null
            && in_array($level, (array) config('burnout.protection.levels', []), true);
    }

    /**
     * Stored scores for these employees over the last `$days`, oldest first.
     *
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, Collection<int, array{date: string, score: float}>>
     */
    public function history(array $employeeIds, int $days = 84): Collection
    {
        if ($employeeIds === []) {
            return collect();
        }

        return BurnoutRiskSnapshot::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('as_of_date', '>=', $this->today()->subDays($days)->toDateString())
            ->orderBy('as_of_date')
            ->get(['employee_id', 'as_of_date', 'score'])
            ->groupBy('employee_id')
            ->map(fn (Collection $rows) => $rows->map(fn (BurnoutRiskSnapshot $row): array => [
                'date' => $row->as_of_date->toDateString(),
                'score' => (float) $row->score,
            ])->values());
    }

    /**
     * Delete assessments older than the retention window.
     *
     * Always leaves the last few weeks alone, whatever the setting says: the
     * trend on every screen reads them.
     */
    public function prune(): int
    {
        $days = (int) config('burnout.retention_days');

        if ($days <= 0) {
            return 0;
        }

        $days = max($days, 2 * (int) config('burnout.window_days'));

        return BurnoutRiskSnapshot::query()
            ->where('as_of_date', '<', $this->today()->subDays($days)->toDateString())
            ->delete();
    }

    /** @param  array<string, mixed>  $factor */
    private function factorSummary(array $factor): string
    {
        $value = (float) $factor['value'];
        $shown = floor($value) === $value ? number_format($value) : number_format($value, 1);

        return "{$factor['label']}: {$shown} {$factor['unit']}";
    }
}
