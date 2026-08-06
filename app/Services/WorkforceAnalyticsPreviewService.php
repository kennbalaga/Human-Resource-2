<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The five headline figures the dashboard shows above the full Workforce Analytics
 * module. This is a preview, not a second implementation: the window, the working
 * population, and the rate formulas are the ones AnalyticsController uses for its
 * default view, so the numbers here match the page the "View analytics" button opens.
 *
 *   Attendance rate  - attendance records against the expected workdays of the month.
 *   Leave rate       - approved leave days measured against that same expectation.
 *   Avg. overtime    - overtime hours logged, averaged over the active headcount.
 *   Punctuality      - check-ins that were not flagged late.
 *   Utilization      - worked hours against the standard daily capacity.
 *
 * The window is month to date because that is what the analytics module defaults to
 * (see AnalyticsRequest), and because "monthly punctuality" only means anything on a
 * calendar month. Weekends are excluded from the expectation the same way the module
 * excludes them, so a Monday reload does not read as a workforce that skipped Sunday.
 */
class WorkforceAnalyticsPreviewService
{
    /**
     * The analytics module reports on active employees only, so the preview does too.
     * Widening this to `on_leave` here would make the two views disagree.
     */
    private const WORKFORCE_STATUS = 'active';

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     range_label: string,
     *     headcount: int,
     *     workdays: int,
     *     expected_days: int,
     *     records: int,
     *     tracked: bool,
     *     metrics: array<int, array{key: string, label: string, value: float, display: string, share: float|null, detail: string, accent: string}>,
     * }
     */
    public function forCurrentMonth(): array
    {
        // "Today" is resolved in the workforce timezone, then dropped back to a plain
        // calendar date: every date the models hand back is a naive midnight, so a
        // naive window stops an eight hour offset shunting a record into another day.
        $to = Carbon::parse(Carbon::now(config('workforce.timezone', 'Asia/Manila'))->toDateString());

        // Two minutes matches the analytics module's own cache, so the preview and the
        // full page cannot drift apart while a user clicks between them.
        return Cache::remember(
            'dashboard.analytics-preview.v1.'.$to->toDateString(),
            now()->addMinutes(2),
            fn (): array => $this->build($to->copy()->startOfMonth(), $to),
        );
    }

    /** @return array<string, mixed> */
    private function build(Carbon $from, Carbon $to): array
    {
        $headcount = Employee::query()->where('employment_status', self::WORKFORCE_STATUS)->count();
        $workdays = $this->workdaysBetween($from, $to);

        // What the workforce was expected to deliver this month, in employee-days. It
        // is the denominator behind three of the five figures, and it is zero on a
        // month that has not reached its first weekday yet.
        $expectedDays = $headcount * $workdays;

        $attendance = $this->attendanceTotals($from, $to);
        $leaveDays = $this->approvedLeaveDays($from, $to);

        $records = $attendance['records'];
        $overtimeHours = round($attendance['overtime_minutes'] / 60, 1);
        $workedHours = round($attendance['worked_minutes'] / 60, 1);
        $capacityHours = round($expectedDays * (int) config('workforce.standard_daily_minutes', 480) / 60, 1);

        // Attendance and leave are capped, because a hospital works weekends and the
        // expectation behind them does not count weekends: a ward that covered all
        // seven days would otherwise report 140% attendance. Utilization is left
        // uncapped on purpose -- a team running past its capacity is the finding.
        $attendanceRate = min(100.0, $this->rate($records, $expectedDays));
        $leaveRate = min(100.0, $this->rate($leaveDays, $expectedDays));
        $punctuality = $this->rate($records - $attendance['late_records'], $records);
        $utilization = $this->rate($attendance['worked_minutes'], $expectedDays * (int) config('workforce.standard_daily_minutes', 480));
        $averageOvertime = $headcount > 0 ? round($overtimeHours / $headcount, 1) : 0.0;

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'range_label' => $from->format('M j').' – '.$to->format('M j, Y'),
            'headcount' => $headcount,
            'workdays' => $workdays,
            'expected_days' => $expectedDays,
            'records' => $records,
            // Drives the empty state: nothing has been captured to preview yet.
            'tracked' => $records > 0 || $leaveDays > 0,
            'metrics' => [
                [
                    'key' => 'attendance_rate',
                    'label' => 'Attendance rate',
                    'value' => $attendanceRate,
                    'display' => $this->percent($attendanceRate),
                    'share' => $attendanceRate,
                    'detail' => number_format($records).' of '.number_format($expectedDays).' expected employee-days',
                    'accent' => 'success',
                ],
                [
                    'key' => 'leave_rate',
                    'label' => 'Leave rate',
                    'value' => $leaveRate,
                    'display' => $this->percent($leaveRate),
                    'share' => $leaveRate,
                    'detail' => $this->number($leaveDays).' approved leave '.str('day')->plural($leaveDays).' taken',
                    'accent' => 'violet',
                ],
                [
                    'key' => 'average_overtime_hours',
                    'label' => 'Avg. overtime hours',
                    'value' => $averageOvertime,
                    'display' => $this->number($averageOvertime).'h',
                    // An hour figure has no natural ceiling, so it carries no meter.
                    'share' => null,
                    'detail' => $this->number($overtimeHours).'h logged across '.number_format($headcount).' active '.str('employee')->plural($headcount),
                    'accent' => 'amber',
                ],
                [
                    'key' => 'punctuality',
                    'label' => 'Monthly punctuality',
                    'value' => $punctuality,
                    'display' => $this->percent($punctuality),
                    'share' => $punctuality,
                    'detail' => number_format($attendance['late_records']).' late '.str('arrival')->plural($attendance['late_records']).' out of '.number_format($records).' check-'.str('in')->plural($records),
                    'accent' => 'success',
                ],
                [
                    'key' => 'utilization',
                    'label' => 'Employee utilization',
                    'value' => $utilization,
                    'display' => $this->percent($utilization),
                    // The bar is clamped even though the figure above it is not.
                    'share' => min(100.0, $utilization),
                    'detail' => $this->number($workedHours).'h worked of '.$this->number($capacityHours).'h capacity',
                    'accent' => 'success',
                ],
            ],
        ];
    }

    /**
     * Every attendance figure the preview needs, in one round trip. Four separate
     * aggregates would be four trips against a remote database for the same rows.
     *
     * @return array{records: int, late_records: int, worked_minutes: int, overtime_minutes: int}
     */
    private function attendanceTotals(Carbon $from, Carbon $to): array
    {
        $row = $this->workforceOnly(AttendanceRecord::query())
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->selectRaw('count(*) as records')
            ->selectRaw("coalesce(sum(case when status = 'late' then 1 else 0 end), 0) as late_records")
            ->selectRaw('coalesce(sum(worked_minutes), 0) as worked_minutes')
            ->selectRaw('coalesce(sum(overtime_minutes), 0) as overtime_minutes')
            ->first();

        return [
            'records' => (int) $row->records,
            'late_records' => (int) $row->late_records,
            'worked_minutes' => (int) $row->worked_minutes,
            'overtime_minutes' => (int) $row->overtime_minutes,
        ];
    }

    /**
     * Approved leave, expanded into the working days it actually covers inside the
     * window. A request is clipped to the month before it is counted, so a leave that
     * started in July does not spend July's days against August.
     */
    private function approvedLeaveDays(Carbon $from, Carbon $to): float
    {
        return (float) $this->workforceOnly(LeaveRequest::query())
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get(['employee_id', 'start_date', 'end_date'])
            ->sum(function (LeaveRequest $leave) use ($from, $to): int {
                $start = $leave->start_date->greaterThan($from) ? $leave->start_date->copy() : $from->copy();
                $end = $leave->end_date->lessThan($to) ? $leave->end_date->copy() : $to->copy();

                return $this->workdaysBetween($start->startOfDay(), $end->startOfDay());
            });
    }

    /** Weekdays in an inclusive window -- the same expectation the analytics module uses. */
    private function workdaysBetween(Carbon $from, Carbon $to): int
    {
        return collect(CarbonPeriod::create($from, $to))
            ->reject(fn ($date): bool => Carbon::instance($date)->isWeekend())
            ->count();
    }

    /** A percentage to one decimal, guarded against the empty month. */
    private function rate(float $part, float $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }

    /** Trims the decimal when it adds nothing: "93.4%" but "100%". */
    private function percent(float $value): string
    {
        return $this->number($value).'%';
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
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
            fn (Builder $employee) => $employee->where('employment_status', self::WORKFORCE_STATUS),
        );
    }
}
