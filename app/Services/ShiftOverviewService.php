<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Splits today's published roster into shift pools for the dashboard.
 *
 * One pool per active shift template, so the day reads the way the floor runs it:
 * Morning, Afternoon, Night, Administrative. Every pool reports its start time and
 * three head counts:
 *
 *   Assigned   - employees rostered onto that shift today, less anyone holding a
 *                day off for the date.
 *   Clocked in - assigned employees with a check-in on the day.
 *   Missing    - assigned employees with no check-in and no approved leave.
 *
 * Clocked in counts anyone who has checked in today, not only those still on the
 * clock. Measuring "still checked in" would quietly move a nurse who finished the
 * morning shift into Missing, which is the one column that has to stay trustworthy.
 * Leave is carried alongside so the three columns reconcile against Assigned.
 *
 * Pools are listed even when nobody is rostered on them. An empty Night pool is the
 * signal a charge nurse most needs to see, so hiding it would defeat the panel.
 */
class ShiftOverviewService
{
    /**
     * Employment statuses that belong to the working roster. Employees marked
     * `on_leave` stay in scope: they are still rostered and still counted.
     */
    private const WORKFORCE_STATUSES = ['active', 'on_leave'];

    /**
     * @return array{
     *     date: string,
     *     date_label: string,
     *     shifts: array<int, array{id: int, code: string, name: string, start_time: string, end_time: string, color: string, crosses_midnight: bool, assigned: int, clocked_in: int, missing: int, on_leave: int, coverage: float}>,
     *     totals: array{assigned: int, clocked_in: int, missing: int, on_leave: int},
     *     coverage: float,
     *     rostered: bool,
     * }
     */
    public function forToday(): array
    {
        // "Today" is resolved in the workforce timezone, then dropped back to a plain
        // calendar date, because every date the models hand back is a naive midnight.
        $date = Carbon::parse(Carbon::now(config('workforce.timezone', 'Asia/Manila'))->toDateString());

        // A check-in should surface quickly on a live shift board, but the dashboard
        // already pays for several queries; a short window keeps a reload cheap.
        return Cache::remember(
            'dashboard.shift-overview.v1.'.$date->toDateString(),
            now()->addSeconds(60),
            fn (): array => $this->build($date),
        );
    }

    /** @return array<string, mixed> */
    private function build(Carbon $date): array
    {
        $day = $date->toDateString();

        $assignmentsByShift = $this->workforceOnly(ScheduleAssignment::query())
            ->whereDate('work_date', $day)
            ->where('status', 'scheduled')
            ->get(['employee_id', 'shift_id'])
            ->groupBy('shift_id')
            ->map(fn (Collection $rows): Collection => $rows->pluck('employee_id')->unique()->values());

        $daysOff = $this->workforceOnly(ScheduleDayOff::query())
            ->whereDate('work_date', $day)
            ->pluck('employee_id')
            ->unique();

        $clockedIn = $this->workforceOnly(AttendanceRecord::query())
            ->whereDate('attendance_date', $day)
            ->whereNotNull('check_in_at')
            ->pluck('employee_id')
            ->unique();

        $onLeave = $this->workforceOnly(LeaveRequest::query())
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->pluck('employee_id')
            ->unique();

        $shifts = Shift::query()
            ->where('is_active', true)
            ->orderBy('start_time')
            ->orderBy('name')
            ->get()
            ->map(fn (Shift $shift): array => $this->pool(
                $shift,
                $assignmentsByShift->get($shift->id, collect()),
                $daysOff,
                $clockedIn,
                $onLeave,
            ))
            ->values()
            ->all();

        $totals = [];
        foreach (['assigned', 'clocked_in', 'missing', 'on_leave'] as $key) {
            $totals[$key] = (int) collect($shifts)->sum($key);
        }

        return [
            'date' => $day,
            'date_label' => $date->format('l, F j, Y'),
            'shifts' => $shifts,
            'totals' => $totals,
            'coverage' => $this->coverage($totals['clocked_in'], $totals['assigned']),
            // Drives the empty state: a day nobody was rostered for has nothing to chart.
            'rostered' => $totals['assigned'] > 0,
        ];
    }

    /**
     * @param  Collection<int, int>  $assignedIds
     * @param  Collection<int, int>  $daysOff
     * @param  Collection<int, int>  $clockedIn
     * @param  Collection<int, int>  $onLeave
     * @return array<string, mixed>
     */
    private function pool(Shift $shift, Collection $assignedIds, Collection $daysOff, Collection $clockedIn, Collection $onLeave): array
    {
        // A day off cancels the roster line, so it never counts as expected coverage.
        $assignedIds = $assignedIds->diff($daysOff);

        $clockedInIds = $assignedIds->intersect($clockedIn);
        // Turning up outranks the leave request, the same way it does on the
        // attendance overview: the employee is on the floor.
        $leaveIds = $assignedIds->diff($clockedInIds)->intersect($onLeave);
        $missingIds = $assignedIds->diff($clockedInIds)->diff($leaveIds);

        return [
            'id' => $shift->id,
            'code' => $shift->code,
            'name' => $shift->name,
            'start_time' => Carbon::parse($shift->start_time)->format('g:i A'),
            'end_time' => Carbon::parse($shift->end_time)->format('g:i A'),
            'color' => $shift->color ?: '#176b43',
            'crosses_midnight' => $shift->crosses_midnight,
            'assigned' => $assignedIds->count(),
            'clocked_in' => $clockedInIds->count(),
            'missing' => $missingIds->count(),
            'on_leave' => $leaveIds->count(),
            'coverage' => $this->coverage($clockedInIds->count(), $assignedIds->count()),
        ];
    }

    /** Share of the assigned pool that has clocked in, as a whole percentage. */
    private function coverage(int $clockedIn, int $assigned): float
    {
        return $assigned > 0 ? round($clockedIn / $assigned * 100, 1) : 0.0;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function workforceOnly(Builder $query): Builder
    {
        return $query->whereHas(
            'employee',
            fn (Builder $employee) => $employee->whereIn('employment_status', self::WORKFORCE_STATUSES),
        );
    }
}
