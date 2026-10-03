<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OfficeLocation;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Services\Scheduling\AttendanceScheduleSettings;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * The employee's own half of the attendance page: where they stand today, and
 * the last week read as calendar days.
 *
 * The week is built from dates rather than from whatever rows exist, because the
 * days that matter most are the ones without a clean record -- a rest day,
 * approved leave, an absence, or a time-in that never got its time-out. A list
 * of "latest records" silently skips all four.
 *
 * Dates follow StaffDashboardService's convention: today is resolved in the
 * office timezone and then dropped to a naive calendar date, since that is the
 * form every date column hands back.
 */
class MyAttendanceService
{
    public const PERIOD_WEEK = 'week';

    public const PERIOD_MONTH = 'month';

    private const HISTORY_DAYS = 7;

    /**
     * How far back an open time-in is still raised. Anything older has long
     * since gone through a timesheet, where it is HR's to settle.
     */
    private const MISSING_TIME_OUT_LOOKBACK_DAYS = 14;

    public function __construct(private readonly AttendanceScheduleSettings $scheduleSettings) {}

    /**
     * @return array{today: array<string, mixed>, days: list<array<string, mixed>>, missing_time_out: list<string>}
     */
    public function forEmployee(Employee $employee, OfficeLocation $office, string $period = self::PERIOD_WEEK): array
    {
        $timezone = $office->timezone;
        $now = Carbon::now($timezone);
        $today = Carbon::parse($now->toDateString());

        /*
         * Two windows, and the rolling week stays the default so nothing that
         * relied on this service changes shape. The month runs from the 1st to
         * today rather than the whole calendar month: days that have not
         * happened are not records, and a fortnight of blank rows below the
         * reader is not history.
         */
        $period = $period === self::PERIOD_MONTH ? self::PERIOD_MONTH : self::PERIOD_WEEK;
        $from = $period === self::PERIOD_MONTH
            ? $today->copy()->startOfMonth()
            : $today->copy()->subDays(self::HISTORY_DAYS - 1);
        $dayCount = (int) $from->diffInDays($today) + 1;

        $records = AttendanceRecord::query()
            ->with(['officeLocation:id,name', 'checkInBiometricDevice:id,name', 'checkOutBiometricDevice:id,name'])
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $today->toDateString())
            ->get()
            ->keyBy(fn (AttendanceRecord $record): string => $record->attendance_date->toDateString());

        $assignments = ScheduleAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $today->toDateString())
            ->get()
            ->keyBy(fn (ScheduleAssignment $assignment): string => $assignment->work_date->toDateString());

        $restDays = ScheduleDayOff::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $today->toDateString())
            ->pluck('work_date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString());

        $leaveDates = $this->leaveDatesBetween($employee, $from, $today);

        $days = collect(range(0, $dayCount - 1))
            ->map(function (int $offset) use ($today, $records, $assignments, $restDays, $leaveDates, $timezone): array {
                $date = $today->copy()->subDays($offset);
                $key = $date->toDateString();

                return $this->day(
                    $date,
                    $offset === 0,
                    $records->get($key),
                    $assignments->has($key),
                    $restDays->contains($key),
                    $leaveDates->contains($key),
                    $timezone,
                );
            })
            ->all();

        $todayKey = $today->toDateString();

        return [
            'today' => $this->today(
                $office,
                $now,
                $today,
                $records->get($todayKey),
                $assignments->get($todayKey),
                $restDays->contains($todayKey),
                $leaveDates->contains($todayKey),
            ),
            'days' => $days,
            'missing_time_out' => $this->missingTimeOut($employee, $today),
        ];
    }

    /**
     * A signature of everything the page draws from this employee's records, so
     * the browser can poll for "has anything changed" without rebuilding the
     * payload above on every tick.
     *
     * Count and latest write together cover the three ways the page goes stale:
     * a punch inserted at a terminal, a time-out HR adds to an open day, and a
     * record removed. It is deliberately not scoped to the window on screen --
     * the history reaches back a month and the missing-time-out banner fourteen
     * days, and an edit to an older record is exactly the kind of correction
     * worth showing rather than hiding until the next hand reload.
     */
    public function revisionFor(Employee $employee): string
    {
        /** @var object{total: int, latest: string|null}|null $state */
        $state = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->selectRaw('COUNT(*) as total, MAX(updated_at) as latest')
            ->first();

        return ((int) ($state->total ?? 0)).':'.($state->latest ?? '');
    }

    /**
     * Today as one state, one heading and one sentence. Attendance is asked
     * first: someone who clocked in on a rest day or a leave day was at work.
     *
     * @return array<string, mixed>
     */
    private function today(
        OfficeLocation $office,
        Carbon $now,
        Carbon $today,
        ?AttendanceRecord $record,
        ?ScheduleAssignment $assignment,
        bool $restDay,
        bool $onLeave,
    ): array {
        $timezone = $office->timezone;
        $window = $this->workWindow($office, $today, $assignment);
        $timeIn = $record?->check_in_at?->copy()->timezone($timezone)->format('g:i A');
        $timeOut = $record?->check_out_at?->copy()->timezone($timezone)->format('g:i A');

        $state = match (true) {
            $record?->check_out_at !== null => 'completed',
            $record?->check_in_at !== null => $record->status === 'late' ? 'late' : 'on_time',
            $restDay => 'rest_day',
            $onLeave => 'leave',
            default => 'not_started',
        };

        [$tone, $icon, $title, $line] = match ($state) {
            'completed' => ['success', 'check-circle', 'Checked out', "You checked out at {$timeOut} and worked {$record->worked_hours} today."],
            'late' => [
                'warning',
                'alert',
                'Checked in · Late'.($record->late_minutes > 0 ? ' '.$this->duration((int) $record->late_minutes) : ''),
                "You checked in at {$timeIn}. Arrivals after {$window['late_after']} are counted as late.",
            ],
            'on_time' => ['success', 'check-circle', 'Checked in · On time', "You checked in at {$timeIn}. Your shift ends at {$window['end']}."],
            'rest_day' => ['neutral', 'moon', 'Rest day', 'You have no shift today.'],
            'leave' => ['info', 'leave', 'On leave', 'Your approved leave covers today.'],
            default => ['neutral', 'clock', 'Not checked in', $this->notStartedLine($now, $window)],
        };

        return [
            'state' => $state,
            'tone' => $tone,
            'icon' => $icon,
            'title' => $title,
            'line' => $line,
            'date_label' => $today->format('l, F j'),
            'works_today' => ! in_array($state, ['rest_day', 'leave'], true),
            'window' => $window,
            'tiles' => [
                [
                    'label' => 'Time in',
                    'value' => $timeIn ?? '—',
                    'detail' => $record?->check_in_at !== null
                        ? $this->source($record->check_in_method_label, $record->checkInBiometricDevice?->name)
                        : null,
                ],
                [
                    'label' => 'Time out',
                    'value' => $timeOut ?? '—',
                    'detail' => match (true) {
                        $timeOut !== null => $this->source($record->check_out_method_label, $record->checkOutBiometricDevice?->name),
                        $timeIn !== null => "Shift ends {$window['end']}",
                        default => null,
                    },
                ],
                [
                    'label' => 'Worked',
                    'value' => match (true) {
                        $timeOut !== null => $record->worked_hours,
                        $timeIn !== null => 'In progress',
                        default => '—',
                    },
                    'detail' => null,
                ],
            ],
        ];
    }

    /**
     * The hours a punch today is actually timed against, mirroring
     * AttendanceService::checkInTiming(): the rostered shift and the schedule
     * grace when schedule-aware attendance is on, the office hours and the
     * office grace otherwise. Showing a rostered shift that lateness is not
     * measured against would tell the employee the wrong cut-off.
     *
     * @return array<string, mixed>
     */
    private function workWindow(OfficeLocation $office, Carbon $today, ?ScheduleAssignment $assignment): array
    {
        $timezone = $office->timezone;
        $date = $today->toDateString();
        $shift = $this->scheduleSettings->scheduleAware() ? $assignment?->shift : null;

        $start = Carbon::parse($date.' '.($shift?->start_time ?? $office->work_start_time), $timezone);
        $end = Carbon::parse($date.' '.($shift?->end_time ?? $office->work_end_time), $timezone);

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        $lateAfter = $start->copy()->addMinutes(
            $shift !== null ? $this->scheduleSettings->graceMinutes() : (int) $office->grace_period_minutes,
        );

        return [
            'start_at' => $start,
            'end_at' => $end,
            'late_after_at' => $lateAfter,
            'start' => $start->format('g:i A'),
            'end' => $end->format('g:i A'),
            'late_after' => $lateAfter->format('g:i A'),
            'hours' => $start->format('g:i A').' – '.$end->format('g:i A'),
            'name' => $shift?->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $window
     */
    private function notStartedLine(Carbon $now, array $window): string
    {
        return match (true) {
            $now->lessThan($window['start_at']) => "Your shift starts at {$window['start']}. You’re marked late after {$window['late_after']}.",
            $now->lessThan($window['late_after_at']) => "Your shift started at {$window['start']}. You’re marked late after {$window['late_after']}.",
            $now->lessThan($window['end_at']) => "Your shift started at {$window['start']}, so a check-in now is marked late.",
            default => "No attendance was recorded for today’s shift, which ended at {$window['end']}.",
        };
    }

    /**
     * One row of the week. A day with no punch still gets a row and a reason,
     * so a gap in the table always says what the gap was.
     *
     * @return array<string, mixed>
     */
    private function day(
        Carbon $date,
        bool $isToday,
        ?AttendanceRecord $record,
        bool $rostered,
        bool $restDay,
        bool $onLeave,
        string $timezone,
    ): array {
        $row = [
            'date_label' => $date->format('D, M j'),
            'is_today' => $isToday,
            'location' => null,
            'in' => null,
            'out' => null,
            'worked' => null,
            'late' => null,
            'overtime' => null,
            'badges' => [],
            'flagged' => false,
        ];

        if ($record?->check_in_at === null) {
            $row['badges'][] = match (true) {
                $record !== null => ['secondary', str((string) $record->status)->headline()->value()],
                $restDay => ['secondary', 'Rest day'],
                $onLeave => ['primary', 'On leave'],
                $isToday => ['secondary', 'Not checked in'],
                // Only a rostered day can be missed; an unrostered one was never owed.
                $rostered => ['danger', 'Absent'],
                default => ['secondary', 'No record'],
            };

            return $row;
        }

        // Today's open time-in is simply a shift in progress, not a gap.
        $missingTimeOut = $record->check_out_at === null && ! $isToday;

        $row['location'] = $record->officeLocation?->name;
        $row['in'] = [
            'time' => $record->check_in_at->copy()->timezone($timezone)->format('g:i A'),
            'source' => $this->source($record->check_in_method_label, $record->checkInBiometricDevice?->name),
        ];

        if ($record->check_out_at !== null) {
            $row['out'] = [
                'time' => $record->check_out_at->copy()->timezone($timezone)->format('g:i A'),
                'source' => $this->source($record->check_out_method_label, $record->checkOutBiometricDevice?->name),
            ];
            $row['worked'] = $record->worked_hours;
        }

        $row['late'] = $record->late_minutes > 0 ? $this->duration((int) $record->late_minutes) : null;
        $row['overtime'] = $record->overtime_minutes > 0 ? $this->duration((int) $record->overtime_minutes) : null;

        $row['badges'][] = match ($record->status) {
            'late' => ['warning', 'Late'],
            'present' => ['success', 'Present'],
            default => ['secondary', str((string) $record->status)->headline()->value()],
        };

        if ($missingTimeOut) {
            $row['badges'][] = ['danger', 'No time-out'];
        }

        if ($record->overtime_minutes > 0) {
            $row['badges'][] = ['primary', 'Overtime'];
        }

        $row['flagged'] = $missingTimeOut;

        return $row;
    }

    /**
     * Past time-ins that never got a time-out. Their worked minutes stay at zero
     * until someone closes them, so they are raised above the table rather than
     * left to be noticed as a "0h 00m" in it.
     *
     * @return list<string>
     */
    private function missingTimeOut(Employee $employee, Carbon $today): array
    {
        return AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->whereDate('attendance_date', '<', $today->toDateString())
            ->whereDate('attendance_date', '>=', $today->copy()->subDays(self::MISSING_TIME_OUT_LOOKBACK_DAYS)->toDateString())
            ->orderBy('attendance_date')
            ->pluck('attendance_date')
            ->map(fn ($date): string => Carbon::parse($date)->format('M j'))
            ->values()
            ->all();
    }

    /**
     * Approved leave expanded into the calendar dates it covers, clipped to the
     * window -- the same expansion StaffDashboardService uses.
     *
     * @return Collection<int, string>
     */
    private function leaveDatesBetween(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        return LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->get(['start_date', 'end_date'])
            ->flatMap(function (LeaveRequest $leave) use ($from, $to): array {
                $start = $leave->start_date->greaterThan($from) ? $leave->start_date->copy() : $from->copy();
                $end = $leave->end_date->lessThan($to) ? $leave->end_date->copy() : $to->copy();

                return collect(CarbonPeriod::create($start, $end))
                    ->map(fn ($date): string => Carbon::instance($date)->toDateString())
                    ->all();
            })
            ->unique()
            ->values();
    }

    /** "27m" under an hour, "6h 53m" from there -- raw minutes stop reading past 60. */
    private function duration(int $minutes): string
    {
        return $minutes < 60
            ? "{$minutes}m"
            : sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }

    private function source(string $label, ?string $deviceName): string
    {
        return $deviceName ? "{$label} · {$deviceName}" : $label;
    }
}
