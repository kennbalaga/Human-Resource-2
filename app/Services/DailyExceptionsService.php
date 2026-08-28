<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Today's two exceptions, by name.
 *
 * The shift board answers "how many": four assigned, three in, one missing. That
 * is the right shape for coverage and the wrong shape for acting on it -- a
 * charge nurse reading "1 missing" cannot ring anybody. This names the people
 * behind the two counts a manager actually does something about:
 *
 *   Unaccounted - rostered today, no check-in, no approved leave, no day off.
 *   On leave    - approved leave covering today, whether rostered or not.
 *
 * Held separate from ShiftOverviewService rather than folded into it, for two
 * reasons. That service is cached once for the whole hospital, and naming
 * individuals is a wider disclosure than counting them -- a department head must
 * see their own unit's absentees and nobody else's -- so every query here is
 * narrowed by `Employee::constrainRelatedQuery` and the cache is keyed per scope.
 * And a panel that lists people wants leave types and return dates the counting
 * service has no reason to load.
 *
 * Only shifts past their grace window are considered. Before that, an absent
 * employee has not missed anything yet, and a panel that lists a nurse as
 * unaccounted eleven minutes into their shift teaches the reader to ignore it.
 * The grace period is ShiftOverviewService's, so the board and this list hold
 * their alarm at the same moment.
 */
class DailyExceptionsService
{
    /**
     * Employees marked `on_leave` stay in scope: they are still rostered, and the
     * board still counts them. Matches ShiftOverviewService.
     */
    private const WORKFORCE_STATUSES = ['active', 'on_leave'];

    /** Names per list. The rest are reported as a count with a link to the module. */
    private const LIST_LIMIT = 5;

    /**
     * @return array{
     *     date: string,
     *     date_label: string,
     *     as_of: string,
     *     settled: bool,
     *     unaccounted: array<int, array<string, mixed>>,
     *     unaccounted_total: int,
     *     on_leave: array<int, array<string, mixed>>,
     *     on_leave_total: int,
     * }
     */
    public function forToday(?User $user): array
    {
        // "Today" is resolved in the workforce timezone and then dropped back to a
        // plain calendar date, because every date the models hand back is a naive
        // midnight. Same treatment as the shift board.
        $now = Carbon::now(config('workforce.timezone', 'Asia/Manila'));
        $date = Carbon::parse($now->toDateString());

        // One minute, matching the shift board, so the counts there and the names
        // here cannot drift apart while somebody reads the two side by side. The
        // scope is part of the key: this payload names people, and a department
        // head must never be served HR's copy of it.
        return Cache::remember(
            'dashboard.daily-exceptions.v1.'.$this->scopeKey($user).'.'.$now->format('Y-m-d-H-i'),
            now()->addSeconds(60),
            fn (): array => $this->build($user, $date, $now),
        );
    }

    /** @return array<string, mixed> */
    private function build(?User $user, Carbon $date, Carbon $now): array
    {
        $day = $date->toDateString();

        $settledShifts = Shift::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (Shift $shift): bool => $this->hasSettled($shift, $now))
            ->keyBy('id');

        // A day nobody's shift has opened on yet has no absentees to report, and
        // the roster query below would be a whereIn over an empty set.
        $assignments = $settledShifts->isEmpty()
            ? collect()
            : $this->scoped(ScheduleAssignment::query(), $user)
                ->whereDate('work_date', $day)
                ->where('status', 'scheduled')
                ->whereIn('shift_id', $settledShifts->keys())
                ->get(['employee_id', 'shift_id']);

        $daysOff = $this->scoped(ScheduleDayOff::query(), $user)
            ->whereDate('work_date', $day)
            ->pluck('employee_id')
            ->unique();

        $clockedIn = $this->scoped(AttendanceRecord::query(), $user)
            ->whereDate('attendance_date', $day)
            ->whereNotNull('check_in_at')
            ->pluck('employee_id')
            ->unique();

        // Fetched as rows rather than ids: the leave list names the type and the
        // return date, and the ids fall out of the same result.
        $leave = $this->scoped(LeaveRequest::query(), $user)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->with(['employee.department', 'leaveType'])
            ->orderBy('end_date')
            ->get();

        $onLeaveIds = $leave->pluck('employee_id')->unique();

        // A day off cancels the roster line, and turning up outranks a leave
        // request -- both the same way the shift board resolves them.
        $unaccountedIds = $assignments->pluck('employee_id')->unique()
            ->diff($daysOff)
            ->diff($clockedIn)
            ->diff($onLeaveIds)
            ->values();

        return [
            'date' => $day,
            'date_label' => $date->format('l, F j'),
            'as_of' => $now->format('g:i A'),
            // Says whether the absence figure means anything yet, so the panel can
            // explain an empty list rather than implying a full house.
            'settled' => $settledShifts->isNotEmpty(),
            'unaccounted' => $this->unaccountedItems($unaccountedIds, $assignments, $settledShifts, $day),
            'unaccounted_total' => $unaccountedIds->count(),
            'on_leave' => $this->leaveItems($leave, $date),
            'on_leave_total' => $onLeaveIds->count(),
        ];
    }

    /**
     * @param  Collection<int, int>  $ids
     * @param  Collection<int, ScheduleAssignment>  $assignments
     * @param  Collection<int, Shift>  $settledShifts
     * @return array<int, array<string, mixed>>
     */
    private function unaccountedItems(Collection $ids, Collection $assignments, Collection $settledShifts, string $day): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        // Which pool each name was expected on. Somebody rostered twice is shown
        // against the first of their shifts rather than listed twice.
        $shiftByEmployee = $assignments
            ->groupBy('employee_id')
            ->map(fn (Collection $rows) => $settledShifts->get($rows->first()->shift_id));

        return Employee::query()
            ->with('department')
            ->whereIn('id', $ids)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(function (Employee $employee) use ($shiftByEmployee, $day): array {
                $shift = $shiftByEmployee->get($employee->id);

                return [
                    'name' => $employee->full_name,
                    'employee_number' => $employee->employee_number,
                    'department' => $employee->department?->name,
                    'shift' => $shift?->name,
                    'span' => $shift
                        ? Carbon::parse($shift->start_time)->format('g:i A').' – '.Carbon::parse($shift->end_time)->format('g:i A')
                        : null,
                    'url' => route('attendance.reports.index', [
                        'employee_id' => $employee->id,
                        'date_from' => $day,
                        'date_to' => $day,
                    ]),
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, LeaveRequest>  $leave
     * @return array<int, array<string, mixed>>
     */
    private function leaveItems(Collection $leave, Carbon $date): array
    {
        return $leave
            // Two approved requests can overlap on one day -- a half day either
            // side of a public holiday, say. The person is off once.
            ->unique('employee_id')
            ->take(self::LIST_LIMIT)
            ->map(fn (LeaveRequest $request): array => [
                'name' => $request->employee?->full_name ?? 'Unknown employee',
                'employee_number' => $request->employee?->employee_number,
                'department' => $request->employee?->department?->name,
                'leave_type' => $request->leaveType?->name ?? 'Leave',
                // Deliberately not "back tomorrow": the day after a leave ends may
                // be a rest day, and the roster is what settles that, not this.
                'until_label' => $request->end_date->isSameDay($date)
                    ? 'Last day today'
                    : 'Until '.$request->end_date->format('M j'),
                'url' => route('leaves.index', [
                    'employee_id' => $request->employee_id,
                    'year' => $request->start_date->year,
                ]),
            ])
            ->values()
            ->all();
    }

    /**
     * Whether a shift has been open long enough for an absence on it to mean
     * anything. The grace period belongs to ShiftOverviewService so the two
     * surfaces cannot disagree about when somebody is late rather than early.
     */
    private function hasSettled(Shift $shift, Carbon $now): bool
    {
        $start = $now->copy()->startOfDay()->setTimeFrom(Carbon::parse($shift->start_time));

        return $now->greaterThanOrEqualTo($start->addMinutes(ShiftOverviewService::GRACE_MINUTES));
    }

    /**
     * The workforce filter and the viewer's own reach, applied together. Every
     * query behind this panel names people, so neither may be skipped.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scoped(Builder $query, ?User $user): Builder
    {
        return Employee::constrainRelatedQuery(
            $query->whereHas(
                'employee',
                fn (Builder $employee) => $employee->whereIn('employment_status', self::WORKFORCE_STATUSES),
            ),
            $user,
        );
    }

    /**
     * The cache key's scope segment. `null` from `supervisedDepartmentIds()` is
     * the org-wide roles and `[]` is an account that supervises nobody: two very
     * different payloads that must never share an entry.
     */
    private function scopeKey(?User $user): string
    {
        if ($user === null) {
            return 'none';
        }

        $departmentIds = $user->supervisedDepartmentIds();

        if ($departmentIds === null) {
            return 'all';
        }

        if ($departmentIds === []) {
            return 'empty';
        }

        sort($departmentIds);

        return 'dept-'.implode('-', $departmentIds);
    }
}
