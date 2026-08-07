<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the daily Present / Late / On leave / Absent breakdown that the dashboard
 * attendance overview charts.
 *
 * Present and Late come straight from the attendance records written by check-in.
 * The other two are derived, because nothing writes an "absent" row:
 *
 *   On leave - the employee has an approved leave request covering the day.
 *   Absent   - the employee was rostered for the day, took no day off, filed no
 *              approved leave, and never checked in.
 *
 * Absent is deliberately measured against the published roster rather than total
 * headcount. A day with no roster is a day nobody was expected, so it reports zero
 * absences instead of flagging the whole workforce.
 */
class AttendanceOverviewService
{
    /** Ranges the dashboard is allowed to chart, in days. */
    public const RANGES = [7, 30];

    /**
     * Employment statuses that belong to the working roster. Employees marked
     * `on_leave` stay in scope: they are exactly who the On leave series counts.
     */
    private const WORKFORCE_STATUSES = ['active', 'on_leave'];

    /** @var array<string, string> */
    private const SERIES = [
        'present' => 'Present',
        'late' => 'Late',
        'on_leave' => 'On leave',
        'absent' => 'Absent',
    ];

    /**
     * @return array{
     *     days: int,
     *     from: string,
     *     to: string,
     *     range_label: string,
     *     series: array<int, array{key: string, label: string, total: int}>,
     *     buckets: array<int, array{date: string, label: string, weekday: string, full_label: string, present: int, late: int, on_leave: int, absent: int, total: int}>,
     *     totals: array<string, int>,
     *     tracked: int,
     *     max: int,
     * }
     */
    public function forRange(int $days): array
    {
        $days = in_array($days, self::RANGES, true) ? $days : self::RANGES[0];

        // The dashboard already pays for several queries; a short window keeps a
        // reload cheap without hiding a check-in that just happened.
        return Cache::remember(
            "dashboard.attendance-overview.v1.{$days}",
            now()->addSeconds(60),
            fn (): array => $this->build($days),
        );
    }

    /** @return array<string, mixed> */
    private function build(int $days): array
    {
        // "Today" is resolved in the workforce timezone, then dropped back to a plain
        // calendar date. Every date the models hand back is a naive midnight, so
        // keeping the window naive too stops an eight hour offset from shunting a
        // record into the neighbouring day.
        $to = Carbon::parse(Carbon::now(config('workforce.timezone', 'Asia/Manila'))->toDateString());
        $from = $to->copy()->subDays($days - 1);

        $attendance = $this->withinWindow(
            $this->workforceOnly(AttendanceRecord::query()), 'attendance_date', $from, $to
        )
            ->get(['employee_id', 'attendance_date', 'status'])
            ->groupBy(fn (AttendanceRecord $record): string => $record->attendance_date->toDateString());

        $rostered = $this->employeeIdsByDate(
            $this->withinWindow($this->workforceOnly(ScheduleAssignment::query()), 'work_date', $from, $to)
                ->where('status', 'scheduled')
                ->get(['employee_id', 'work_date']),
            'work_date',
        );

        $daysOff = $this->employeeIdsByDate(
            $this->withinWindow($this->workforceOnly(ScheduleDayOff::query()), 'work_date', $from, $to)
                ->get(['employee_id', 'work_date']),
            'work_date',
        );

        $onLeave = $this->leaveIdsByDate($from, $to);

        $buckets = collect(CarbonPeriod::create($from, $to))
            ->map(fn ($date): array => $this->bucket(
                Carbon::instance($date),
                $attendance,
                $rostered,
                $daysOff,
                $onLeave,
            ))
            ->values()
            ->all();

        $totals = [];
        foreach (array_keys(self::SERIES) as $key) {
            $totals[$key] = (int) collect($buckets)->sum($key);
        }

        return [
            'days' => $days,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'range_label' => $from->format('M j').' – '.$to->format('M j, Y'),
            'series' => collect(self::SERIES)
                ->map(fn (string $label, string $key): array => [
                    'key' => $key,
                    'label' => $label,
                    'total' => $totals[$key],
                ])
                ->values()
                ->all(),
            'buckets' => $buckets,
            'totals' => $totals,
            'tracked' => array_sum($totals),
            // The tallest column sets the scale; never zero, so the bar maths is safe.
            'max' => max(1, (int) collect($buckets)->max('total')),
        ];
    }

    /**
     * @param  Collection<string, Collection<int, AttendanceRecord>>  $attendance
     * @param  Collection<string, Collection<int, int>>  $rostered
     * @param  Collection<string, Collection<int, int>>  $daysOff
     * @param  Collection<string, Collection<int, int>>  $onLeave
     * @return array<string, mixed>
     */
    private function bucket(Carbon $date, Collection $attendance, Collection $rostered, Collection $daysOff, Collection $onLeave): array
    {
        $key = $date->toDateString();
        $records = $attendance->get($key, collect());

        $lateIds = $records->where('status', 'late')->pluck('employee_id')->unique();
        // Anything that is not explicitly late counts as present, so a status added
        // later (half day, for instance) still lands in the attended column rather
        // than silently inflating the absences.
        $presentIds = $records->pluck('employee_id')->unique()->diff($lateIds);
        $attendedIds = $presentIds->merge($lateIds);

        // A check-in outranks the leave request: the employee turned up.
        $leaveIds = $onLeave->get($key, collect())->diff($attendedIds);

        $expectedIds = $rostered->get($key, collect())->diff($daysOff->get($key, collect()));
        $absentIds = $expectedIds->diff($attendedIds)->diff($leaveIds);

        $counts = [
            'present' => $presentIds->count(),
            'late' => $lateIds->count(),
            'on_leave' => $leaveIds->count(),
            'absent' => $absentIds->count(),
        ];

        return $counts + [
            'date' => $key,
            'label' => $date->format('M j'),
            'weekday' => $date->format('D'),
            'full_label' => $date->format('l, M j'),
            'total' => array_sum($counts),
        ];
    }

    /**
     * Expand every approved leave request into the days it covers inside the window.
     *
     * @return Collection<string, Collection<int, int>>
     */
    private function leaveIdsByDate(Carbon $from, Carbon $to): Collection
    {
        $byDate = collect();

        $this->workforceOnly(LeaveRequest::query())
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get(['employee_id', 'start_date', 'end_date'])
            ->each(function (LeaveRequest $leave) use ($byDate, $from, $to): void {
                // Clip the request to the charted window before walking its days.
                $start = $leave->start_date->greaterThan($from) ? $leave->start_date->copy() : $from->copy();
                $end = $leave->end_date->lessThan($to) ? $leave->end_date->copy() : $to->copy();

                foreach (CarbonPeriod::create($start->startOfDay(), $end->startOfDay()) as $date) {
                    $key = Carbon::instance($date)->toDateString();
                    $byDate->put($key, $byDate->get($key, collect())->push($leave->employee_id));
                }
            });

        return $byDate->map(fn (Collection $ids): Collection => $ids->unique()->values());
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return Collection<string, Collection<int, int>>
     */
    private function employeeIdsByDate(Collection $rows, string $dateColumn): Collection
    {
        return $rows
            ->groupBy(fn ($row): string => $row->{$dateColumn}->toDateString())
            ->map(fn (Collection $group): Collection => $group->pluck('employee_id')->unique()->values());
    }

    /**
     * Date columns are written through the model's datetime format, so a plain
     * `whereBetween` on `Y-m-d` strings compares "2026-08-06 00:00:00" against
     * "2026-08-06" and silently drops the last day of the window.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function withinWindow(Builder $query, string $column, Carbon $from, Carbon $to): Builder
    {
        return $query
            ->whereDate($column, '>=', $from->toDateString())
            ->whereDate($column, '<=', $to->toDateString());
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
