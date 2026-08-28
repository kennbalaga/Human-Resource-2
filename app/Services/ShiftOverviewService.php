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
 *
 * Each pool also carries where it sits against the clock -- upcoming, just started,
 * in progress, ended. Head counts alone cannot tell those apart, so a shift eleven
 * minutes old would otherwise report eleven people "missing" in alarm red when
 * nobody is late yet. The phase lets the panel hold that alarm until it means
 * something.
 */
class ShiftOverviewService
{
    /**
     * Employment statuses that belong to the working roster. Employees marked
     * `on_leave` stay in scope: they are still rostered and still counted.
     */
    private const WORKFORCE_STATUSES = ['active', 'on_leave'];

    /**
     * How long after a shift opens before an absent employee reads as missing
     * rather than simply not in yet. Long enough to cover a handover and a queue
     * at the terminal, short enough that a real no-show still surfaces early.
     */
    public const GRACE_MINUTES = 30;

    /**
     * @return array{
     *     date: string,
     *     date_label: string,
     *     as_of: string,
     *     shifts: array<int, array{id: int, code: string, name: string, start_time: string, end_time: string, span_label: string, duration_label: string, color: string, crosses_midnight: bool, phase: string, phase_label: string, awaiting: bool, elapsed: float, minutes_in: int|null, assigned: int, clocked_in: int, missing: int, on_leave: int, coverage: float}>,
     *     totals: array{assigned: int, clocked_in: int, missing: int, on_leave: int},
     *     coverage: float,
     *     rostered: bool,
     * }
     */
    public function forToday(): array
    {
        // "Today" is resolved in the workforce timezone, then dropped back to a plain
        // calendar date, because every date the models hand back is a naive midnight.
        $now = Carbon::now(config('workforce.timezone', 'Asia/Manila'));
        $date = Carbon::parse($now->toDateString());

        // A check-in should surface quickly on a live shift board, but the dashboard
        // already pays for several queries; a short window keeps a reload cheap. The
        // phase labels ride the same window, which is finer than they need to be.
        return Cache::remember(
            'dashboard.shift-overview.v2.'.$now->format('Y-m-d-H-i'),
            now()->addSeconds(60),
            fn (): array => $this->build($date, $now),
        );
    }

    /** @return array<string, mixed> */
    private function build(Carbon $date, Carbon $now): array
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
                $now,
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
            'as_of' => $now->format('g:i A'),
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
    private function pool(
        Shift $shift,
        Collection $assignedIds,
        Collection $daysOff,
        Collection $clockedIn,
        Collection $onLeave,
        Carbon $now,
    ): array {
        // A day off cancels the roster line, so it never counts as expected coverage.
        $assignedIds = $assignedIds->diff($daysOff);

        $clockedInIds = $assignedIds->intersect($clockedIn);
        // Turning up outranks the leave request, the same way it does on the
        // attendance overview: the employee is on the floor.
        $leaveIds = $assignedIds->diff($clockedInIds)->intersect($onLeave);
        $missingIds = $assignedIds->diff($clockedInIds)->diff($leaveIds);

        $start = Carbon::parse($shift->start_time)->format('g:i A');
        $end = Carbon::parse($shift->end_time)->format('g:i A');

        return array_merge([
            'id' => $shift->id,
            'code' => $shift->code,
            'name' => $shift->name,
            'start_time' => $start,
            'end_time' => $end,
            'span_label' => $start.' – '.$end,
            'duration_label' => $this->durationLabel($shift),
            'color' => $shift->color ?: '#176b43',
            'crosses_midnight' => $shift->crosses_midnight,
            'assigned' => $assignedIds->count(),
            'clocked_in' => $clockedInIds->count(),
            'missing' => $missingIds->count(),
            'on_leave' => $leaveIds->count(),
            'coverage' => $this->coverage($clockedInIds->count(), $assignedIds->count()),
        ], $this->phase($shift, $now));
    }

    /**
     * Where the shift sits against the clock right now.
     *
     * `awaiting` is the one the panel leans on: while it holds, an absent employee
     * has not missed anything yet, so the count reads neutrally as "not yet in".
     *
     * @return array{phase: string, phase_label: string, awaiting: bool, elapsed: float, minutes_in: int|null}
     */
    private function phase(Shift $shift, Carbon $now): array
    {
        [$start, $end] = $this->window($shift, $now);

        if ($now->lessThan($start)) {
            return [
                'phase' => 'upcoming',
                'phase_label' => 'Starts in '.$this->countdown($now, $start),
                'awaiting' => true,
                'elapsed' => 0.0,
                'minutes_in' => null,
            ];
        }

        if ($now->greaterThanOrEqualTo($end)) {
            return [
                'phase' => 'ended',
                'phase_label' => 'Ended',
                'awaiting' => false,
                'elapsed' => 1.0,
                'minutes_in' => null,
            ];
        }

        $minutesIn = (int) floor($start->diffInMinutes($now));
        $span = max(1, (int) floor($start->diffInMinutes($end)));
        $fresh = $minutesIn < self::GRACE_MINUTES;

        return [
            'phase' => $fresh ? 'starting' : 'active',
            'phase_label' => $fresh ? 'Just started' : 'In progress',
            'awaiting' => $fresh,
            'elapsed' => round(min(1, $minutesIn / $span), 4),
            'minutes_in' => $minutesIn,
        ];
    }

    /**
     * The shift's real interval today. Built off `$now` rather than the plain
     * calendar date the queries use, so both sides of every comparison sit in the
     * workforce timezone -- a naive midnight would be the app's, and the phase would
     * come out hours adrift. A pool that crosses midnight closes on the following
     * calendar day, so the end is pushed rather than wrapped.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Shift $shift, Carbon $now): array
    {
        $start = $now->copy()->startOfDay()->setTimeFrom(Carbon::parse($shift->start_time));
        $end = $now->copy()->startOfDay()->setTimeFrom(Carbon::parse($shift->end_time));

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }

    /** Time until a shift opens, coarse on purpose: "4h 20m", "35m", "under a minute". */
    private function countdown(Carbon $now, Carbon $start): string
    {
        $minutes = (int) floor($now->diffInMinutes($start));

        if ($minutes < 1) {
            return 'under a minute';
        }

        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        if ($hours < 1) {
            return $remainder.'m';
        }

        return $remainder > 0 ? $hours.'h '.$remainder.'m' : $hours.'h';
    }

    /** The gross span of the shift, breaks included, as "9h" or "8h 30m". */
    private function durationLabel(Shift $shift): string
    {
        $start = Carbon::parse($shift->start_time);
        $end = Carbon::parse($shift->end_time);

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        $minutes = (int) floor($start->diffInMinutes($end));
        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        return $remainder > 0 ? $hours.'h '.$remainder.'m' : $hours.'h';
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
