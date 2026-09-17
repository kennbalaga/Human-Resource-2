<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\BiometricEnrollment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\OfficeLocation;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\ShiftSwapRequest;
use App\Models\Timesheet;
use App\Services\Burnout\BurnoutRiskService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Everything the Staff / Nurse dashboard shows, for one employee and nobody else.
 *
 * This is the self-service counterpart to the manager dashboard: every figure is
 * scoped to the signed-in employee's own id, so a nurse opening the page sees her
 * own shift, her own hours, and her own leave -- never the ward roster. The whole
 * payload is built here rather than in the view so the "is this my data?" question
 * has exactly one place to be answered.
 *
 * Nothing on this page is cached. The other dashboard services cache for a minute
 * because they aggregate the whole hospital; this one reads a single employee's
 * rows, and the attendance card has to flip the moment its own Clock Out button
 * posts back. A stale "still clocked in" card is worse than the queries it saves.
 *
 * Three conventions run through the whole file:
 *
 *   Dates are naive.   Today is resolved in the office timezone and then dropped to
 *                      a plain calendar date, because every date the models hand
 *                      back is a naive midnight. Comparing the two forms directly
 *                      is what shunts a record into the wrong day.
 *   Expectation is     A day counts as expected only once it is over. Absences are
 *   yesterday's.       therefore never counted for today, so a nurse on the night
 *                      shift is not marked absent at breakfast.
 *   Attendance beats   Someone who clocked in on a leave day was on the floor. The
 *   leave.             ward saw them, so the dashboard counts them present -- the
 *                      same precedence ShiftOverviewService uses.
 */
class StaffDashboardService
{
    /** How far ahead "Upcoming schedule" looks, and how many rows it keeps. */
    private const UPCOMING_DAYS = 14;

    private const UPCOMING_LIMIT = 6;

    /** Months on the personal attendance trend, current month included. */
    private const TREND_MONTHS = 6;

    /** How far back the shift-change alert looks for a recorded change. */
    private const CHANGE_LOOKBACK_DAYS = 14;

    /**
     * A roster line added this recently, for a date this soon, is a short-notice
     * change rather than ordinary publishing -- that is what earns the alert.
     */
    private const SHORT_NOTICE_ANNOUNCED_DAYS = 3;

    private const SHORT_NOTICE_WORK_DAYS = 7;

    private const CHANGE_LIMIT = 5;

    /** The three balances the leave widget is specified to show, in order. */
    private const LEAVE_CODES = ['VAC', 'SICK', 'EMER'];

    public function __construct(
        private readonly AttendanceCaptureSettings $captureSettings,
        private readonly BurnoutRiskService $burnoutRisk,
    ) {}

    /**
     * @return array{
     *     employee: array<string, mixed>,
     *     today: array<string, mixed>,
     *     shift: array<string, mixed>,
     *     upcoming: array<string, mixed>,
     *     attendance_summary: array<string, mixed>,
     *     timesheet: array<string, mixed>,
     *     leave: array<string, mixed>,
     *     schedule_changes: array<string, mixed>,
     *     overtime: array<string, mixed>,
     *     analytics: array<string, mixed>,
     * }
     */
    public function forEmployee(Employee $employee): array
    {
        $employee->loadMissing(['department', 'position']);

        $office = OfficeLocation::query()->where('is_active', true)->first();
        $timezone = $office?->timezone ?: (string) config('workforce.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $today = Carbon::parse($now->toDateString());

        // One read of the employee's own record covers the monthly tallies, the
        // overtime card, and every column of the six-month trend, so those three
        // widgets cannot disagree with each other about the same day.
        $ledger = $this->ledger(
            $employee,
            $today->copy()->startOfMonth()->subMonthsNoOverflow(self::TREND_MONTHS - 1),
            $today,
        );

        $todayRecord = $ledger['records']->get($today->toDateString());
        $todayAssignment = $ledger['assignments']->get($today->toDateString());

        $summary = $this->monthSummary($ledger, $today->copy()->startOfMonth(), $today, $today);

        $leave = $this->leave($employee, $today);

        return [
            'employee' => [
                'name' => $employee->full_name,
                'first_name' => $employee->first_name,
                'employee_number' => $employee->employee_number,
                'department' => $employee->department?->name,
                'position' => $employee->position?->title,
            ],
            'today' => $this->today($employee, $office, $timezone, $now, $today, $todayRecord, $todayAssignment),
            'shift' => $this->shiftToday($employee, $office, $timezone, $now, $today, $ledger, $todayRecord),
            'upcoming' => $this->upcoming($employee, $today),
            'attendance_summary' => $summary,
            'timesheet' => $this->timesheet($employee, $today),
            'leave' => $leave,
            'schedule_changes' => $this->scheduleChanges($employee, $today),
            'overtime' => $this->overtime($ledger, $today->copy()->startOfMonth(), $today),
            'analytics' => $this->analytics($ledger, $today, $summary),
            // The vacation balance is already on the leave widget, so the card
            // is handed it rather than reading it again.
            'burnout' => $this->burnoutRisk->card(
                $employee,
                (float) (collect($leave['balances'])->firstWhere('code', config('burnout.vacation_leave_code'))['available'] ?? 0),
            ),
        ];
    }

    /**
     * The critical card: where this employee stands right now against the biometric
     * Time & Attendance system.
     *
     * The capture mode decides which action is even offered. Under Biometric Only
     * the buttons are withheld rather than shown-and-rejected, because a nurse who
     * taps a button that then refuses her is a nurse who thinks she is clocked in.
     *
     * @param  array<string, mixed>|null  $assignment
     * @return array<string, mixed>
     */
    private function today(
        Employee $employee,
        ?OfficeLocation $office,
        string $timezone,
        Carbon $now,
        Carbon $today,
        ?AttendanceRecord $record,
        ?array $assignment,
    ): array {
        $state = match (true) {
            $record?->check_out_at !== null => 'completed',
            $record?->check_in_at !== null => 'clocked_in',
            default => 'not_started',
        };

        $manualAllowed = $this->captureSettings->manualAllowed();

        // The scheduled window is the shift the employee was actually rostered for;
        // the office's own hours are only the fallback for an unrostered day.
        $scheduled = $assignment['formatted_time']
            ?? ($office !== null
                ? Carbon::parse($office->work_start_time)->format('g:i A').'–'.Carbon::parse($office->work_end_time)->format('g:i A')
                : null);

        return [
            'state' => $state,
            'date_label' => $today->format('l, F j, Y'),
            'timezone' => $timezone,
            'now_label' => $now->format('g:i A'),

            'status_label' => match ($state) {
                'completed' => 'Clocked Out',
                'clocked_in' => 'Clocked In',
                default => 'Not Clocked In',
            },
            'status_tone' => match ($state) {
                'completed' => 'neutral',
                'clocked_in' => 'success',
                default => 'idle',
            },

            'time_in' => $record?->check_in_at?->timezone($timezone)->format('g:i A'),
            'time_out' => $record?->check_out_at?->timezone($timezone)->format('g:i A'),
            'scheduled' => $scheduled,
            'total_hours' => $record?->check_out_at !== null ? $record->worked_hours : null,

            // Seeds the live "on the clock" counter; the card still reads correctly
            // with JavaScript off because Time In is rendered beside it.
            'check_in_epoch' => $record?->check_in_at?->getTimestamp(),

            'punctuality' => $this->punctuality($record),
            'in_source' => $this->captureSource($record?->check_in_method, $record?->checkInBiometricDevice?->name),
            'out_source' => $record?->check_out_at !== null
                ? $this->captureSource($record->check_out_method, $record->checkOutBiometricDevice?->name)
                : null,
            'biometric' => $this->biometricLink($employee),

            'capture_mode' => $this->captureSettings->mode(),
            'capture_mode_label' => str($this->captureSettings->mode())->replace('_', ' ')->title()->value(),
            'manual_allowed' => $manualAllowed,
            'reason_required' => $this->captureSettings->manualReasonRequired(),
            'can_clock_in' => $manualAllowed && $state === 'not_started' && $office !== null,
            'can_clock_out' => $manualAllowed && $state === 'clocked_in' && $office !== null,
            'office' => $office === null ? null : ['id' => $office->id, 'name' => $office->name],
        ];
    }

    /**
     * How the day was captured, and on which terminal. The device name is what makes
     * the card visibly a readout of the biometric system rather than a web form.
     *
     * @return array{label: string, device: string|null, biometric: bool}|null
     */
    private function captureSource(?string $method, ?string $deviceName): ?array
    {
        if ($method === null) {
            return null;
        }

        return [
            'label' => $method === 'biometric' ? 'Biometric scan' : 'Web check-in',
            'device' => $deviceName,
            'biometric' => $method === 'biometric',
        ];
    }

    /**
     * On time, or how far past the grace period. Late minutes are measured from the
     * scheduled start by AttendanceService, so they are quoted, never recomputed.
     *
     * @return array{label: string, tone: string, detail: string|null}
     */
    private function punctuality(?AttendanceRecord $record): array
    {
        if ($record?->check_in_at === null) {
            return ['label' => 'Awaiting first scan', 'tone' => 'idle', 'detail' => null];
        }

        if ($record->status === 'late') {
            return [
                'label' => 'Late',
                'tone' => 'warning',
                'detail' => $record->late_minutes.' '.str('minute')->plural($record->late_minutes).' past schedule',
            ];
        }

        return ['label' => 'On Time', 'tone' => 'success', 'detail' => 'Within the grace period'];
    }

    /**
     * The employee's own terminal enrolment. Shown whether or not it exists: an
     * unenrolled nurse needs to know that before her shift starts, not after her
     * finger is refused at the door.
     *
     * @return array<string, mixed>
     */
    private function biometricLink(Employee $employee): array
    {
        $enrollment = BiometricEnrollment::query()
            ->with('device')
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->orderByDesc('enrolled_at')
            ->first();

        $device = $enrollment?->device;

        return [
            'enrolled' => $enrollment !== null,
            'external_id' => $enrollment?->external_user_id,
            'device' => $device?->name,
            'online' => (bool) $device?->is_active,
            'last_sync' => $device?->last_seen_at?->diffForHumans(),
        ];
    }

    /**
     * Today's assignment only -- one card, one shift, never the ward's roster.
     *
     * A rest day and an approved leave are shifts too as far as this card is
     * concerned: they answer the same question ("am I working today?") and hiding
     * them would leave the card blank on exactly the days it matters most.
     *
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function shiftToday(
        Employee $employee,
        ?OfficeLocation $office,
        string $timezone,
        Carbon $now,
        Carbon $today,
        array $ledger,
        ?AttendanceRecord $record,
    ): array {
        $date = $today->toDateString();
        $assignment = $ledger['assignments']->get($date);
        $onLeave = $ledger['leave_dates']->contains($date);
        $restDay = $ledger['day_off_dates']->contains($date);

        $base = [
            'date_label' => $today->format('l, F j, Y'),
            'department' => $employee->department?->name ?? 'Unassigned',
            'position' => $employee->position?->title ?? 'Unassigned',
            // The hospital has no ward-level field of its own, so the assigned area
            // is the site whose terminal captures this employee's attendance.
            'area' => $office?->name ?? $employee->department?->name ?? 'Not assigned',
        ];

        // A rest day outranks a roster line: ShiftOverviewService drops anyone with a
        // day off out of the assigned pool, so the two views agree about who works.
        if ($restDay) {
            return $base + [
                'kind' => 'rest_day',
                'name' => 'Rest Day',
                'hours' => 'No working hours',
                'status' => 'Rest day',
                'status_tone' => 'neutral',
                'color' => null,
            ];
        }

        if ($onLeave && $record?->check_in_at === null) {
            return $base + [
                'kind' => 'leave',
                'name' => 'Approved Leave',
                'hours' => 'No working hours',
                'status' => 'On approved leave',
                'status_tone' => 'violet',
                'color' => null,
            ];
        }

        if ($assignment === null) {
            return $base + [
                'kind' => 'none',
                'name' => 'No shift assigned',
                'hours' => '—',
                'status' => 'Nothing rostered',
                'status_tone' => 'idle',
                'color' => null,
            ];
        }

        return $base + [
            'kind' => 'shift',
            'name' => $assignment['name'],
            'hours' => $assignment['formatted_time'],
            'crosses_midnight' => $assignment['crosses_midnight'],
            'color' => $assignment['color'],
            'status' => $this->shiftStatus($assignment, $timezone, $today, $now, $record),
            'status_tone' => match (true) {
                $record?->check_out_at !== null => 'neutral',
                $record?->check_in_at !== null => 'success',
                default => 'idle',
            },
        ];
    }

    /**
     * Where the employee is inside today's shift. Attendance is asked first, because
     * "clocked in" is a fact and "the shift has started" is only a clock reading.
     *
     * @param  array<string, mixed>  $assignment
     */
    private function shiftStatus(array $assignment, string $timezone, Carbon $today, Carbon $now, ?AttendanceRecord $record): string
    {
        if ($record?->check_out_at !== null) {
            return 'Completed';
        }

        if ($record?->check_in_at !== null) {
            return 'In progress';
        }

        $start = Carbon::parse($today->toDateString().' '.$assignment['start_time'], $timezone);

        return $now->lessThan($start) ? 'Scheduled' : 'Awaiting clock-in';
    }

    /**
     * The employee's next few shifts -- assignments, rest days, and approved leave
     * on the dates they fall, with nothing from anybody else's roster.
     *
     * @return array<string, mixed>
     */
    private function upcoming(Employee $employee, Carbon $today): array
    {
        $from = $today->copy()->addDay();
        $to = $today->copy()->addDays(self::UPCOMING_DAYS);

        $assignments = $this->assignmentsBetween($employee, $from, $to);
        $dayOffs = $this->dayOffDatesBetween($employee, $from, $to);
        $leaveDates = $this->leaveDatesBetween($employee, $from, $to);

        $rows = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            if (count($rows) >= self::UPCOMING_LIMIT) {
                break;
            }

            $date = Carbon::instance($day);
            $key = $date->toDateString();
            $assignment = $assignments->get($key);

            // A date with no roster line, no rest day, and no leave is simply not
            // published yet. Listing it as "Rest Day" would be a promise the roster
            // has not made, so the row is left out entirely.
            $row = match (true) {
                $dayOffs->contains($key) => ['shift' => 'Rest Day', 'type' => 'rest', 'time' => '—', 'color' => null],
                $leaveDates->contains($key) => ['shift' => 'Approved Leave', 'type' => 'leave', 'time' => '—', 'color' => null],
                $assignment !== null => [
                    'shift' => $assignment['name'],
                    'type' => 'shift',
                    'time' => $assignment['formatted_time'],
                    'color' => $assignment['color'],
                ],
                default => null,
            };

            if ($row === null) {
                continue;
            }

            $rows[] = $row + [
                'date' => $key,
                'date_label' => $date->format('M j'),
                'weekday' => $date->format('D'),
                'department' => $employee->department?->name ?? 'Unassigned',
            ];
        }

        return [
            'rows' => $rows,
            'range_label' => $from->format('M j').' – '.$to->format('M j, Y'),
        ];
    }

    /**
     * This month's own attendance tally.
     *
     * Absences are derived, not stored: a rostered day that closed with no check-in,
     * no rest day, and no approved leave is the only thing an absence can be. The
     * rate then measures days attended against days that were genuinely expected --
     * leave is left out of the denominator, because approved leave is not a miss.
     *
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function monthSummary(array $ledger, Carbon $from, Carbon $to, Carbon $today): array
    {
        $present = 0;
        $late = 0;
        $attended = collect();

        foreach ($ledger['records'] as $date => $record) {
            if ($date < $from->toDateString() || $date > $to->toDateString() || $record->check_in_at === null) {
                continue;
            }

            $attended->push($date);

            if ($record->status === 'late') {
                $late++;
            } else {
                $present++;
            }
        }

        $leaveDays = $ledger['leave_dates']
            ->filter(fn (string $date): bool => $date >= $from->toDateString() && $date <= $to->toDateString())
            ->reject(fn (string $date): bool => $attended->contains($date));

        $absentDays = $ledger['assignments']->keys()
            ->filter(fn (string $date): bool => $date >= $from->toDateString() && $date <= $to->toDateString())
            // Today is still running, so it cannot have been missed yet.
            ->filter(fn (string $date): bool => $date < $today->toDateString())
            ->reject(fn (string $date): bool => $attended->contains($date))
            ->reject(fn (string $date): bool => $ledger['day_off_dates']->contains($date))
            ->reject(fn (string $date): bool => $ledger['leave_dates']->contains($date));

        $absent = $absentDays->count();
        $expected = $present + $late + $absent;

        return [
            'month_label' => $from->format('F Y'),
            'range_label' => $from->format('M j').' – '.$to->format('M j, Y'),
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'leave' => $leaveDays->count(),
            'expected' => $expected,
            'rate' => $expected > 0 ? round(($present + $late) / $expected * 100, 1) : 0.0,
            'punctuality' => $present + $late > 0 ? round($present / ($present + $late) * 100, 1) : 0.0,
            'worked_hours' => round($ledger['worked_minutes_by_month']->get($from->format('Y-m'), 0) / 60, 1),
            'tracked' => $expected > 0 || $leaveDays->isNotEmpty(),
        ];
    }

    /**
     * The timesheet covering today. Periods are the Monday-to-Sunday weeks
     * TimesheetService itself creates, so the card names the same period the
     * Timesheets page will open.
     *
     * @return array<string, mixed>
     */
    private function timesheet(Employee $employee, Carbon $today): array
    {
        $periodStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $periodEnd = $today->copy()->endOfWeek(Carbon::SUNDAY);

        $timesheet = Timesheet::query()
            ->where('employee_id', $employee->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->whereDate('period_end', $periodEnd->toDateString())
            ->first();

        // A period with no timesheet row has had nothing approved into it yet, which
        // is a state of its own -- not a draft the employee has forgotten to send.
        $status = $timesheet?->status ?? 'none';

        $presentation = match ($status) {
            'approved' => ['label' => 'Approved', 'tone' => 'success', 'icon' => 'check-circle', 'attention' => false],
            'submitted' => ['label' => 'Pending approval', 'tone' => 'info', 'icon' => 'clock', 'attention' => false],
            'rejected' => ['label' => 'Returned for correction', 'tone' => 'danger', 'icon' => 'close', 'attention' => true],
            'draft' => ['label' => 'Draft — pending submission', 'tone' => 'warning', 'icon' => 'edit', 'attention' => true],
            default => ['label' => 'Not started', 'tone' => 'idle', 'icon' => 'circle', 'attention' => false],
        };

        return $presentation + [
            'status' => $status,
            'period_label' => $periodStart->format('M j').' – '.$periodEnd->format('M j, Y'),
            'total_hours' => $this->hours($timesheet?->total_minutes ?? 0),
            'overtime_hours' => $this->hours($timesheet?->overtime_minutes ?? 0),
            'late_minutes' => (int) ($timesheet?->late_minutes ?? 0),
        ];
    }

    /**
     * Leave credits and the employee's own recent requests.
     *
     * The three balances are listed whether or not a balance row exists: a nurse who
     * has never been granted emergency credits still needs to see the zero.
     *
     * @return array<string, mixed>
     */
    private function leave(Employee $employee, Carbon $today): array
    {
        $balances = LeaveBalance::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('year', $today->year)
            ->whereHas('leaveType', fn ($query) => $query->whereIn('code', self::LEAVE_CODES))
            ->get()
            ->keyBy(fn (LeaveBalance $balance): string => $balance->leaveType->code);

        $rows = collect(self::LEAVE_CODES)->map(function (string $code) use ($balances): array {
            $balance = $balances->get($code);

            return [
                'code' => $code,
                'name' => $balance?->leaveType->name ?? match ($code) {
                    'VAC' => 'Vacation Leave',
                    'SICK' => 'Sick Leave',
                    default => 'Emergency Leave',
                },
                'available' => $balance?->available_days ?? 0.0,
                'used' => (float) ($balance?->used_days ?? 0),
                'entitled' => (float) ($balance?->entitled_days ?? 0) + (float) ($balance?->carried_over_days ?? 0),
            ];
        })->all();

        $recent = LeaveRequest::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->latest('created_at')
            ->limit(3)
            ->get()
            ->map(fn (LeaveRequest $request): array => [
                'type' => $request->leaveType?->name ?? 'Leave',
                // The lifecycle status, not the bare workflow one: an employee
                // reading their own card wants to know the leave is behind them,
                // not that it was approved back in July.
                'status' => $request->lifecycle_status,
                'status_label' => str($request->lifecycle_status)->replace('_', ' ')->title()->value(),
                'tone' => match ($request->lifecycle_status) {
                    'approved', 'upcoming', 'completed' => 'success',
                    'ongoing' => 'info',
                    'pending' => 'warning',
                    'rejected' => 'danger',
                    default => 'idle',
                },
                'range' => $request->start_date->format('M j')
                    .($request->start_date->isSameDay($request->end_date) ? '' : ' – '.$request->end_date->format('M j')),
                'year' => $request->end_date->format('Y'),
                'days' => (float) $request->requested_days,
            ])
            ->all();

        return [
            'year' => $today->year,
            'balances' => $rows,
            'total_available' => round(collect($rows)->sum('available'), 1),
            'recent' => $recent,
        ];
    }

    /**
     * Changes to this employee's published schedule, each with the shift it replaced.
     *
     * Only changes the system can evidence are listed. Nothing here is inferred from
     * a bare `updated_at`: a row that merely changed carries no record of what it
     * used to say, and an alert that cannot name the previous shift is not an alert.
     * That leaves three sources, and all three know both sides of the change:
     *
     *   Swap      an approved swap trades two assignments, so the employee's old
     *             slot and new slot are both still on file.
     *   Rest day  a day off laid over a roster line supersedes it; the line it
     *             supersedes is the previous shift.
     *   Added     a roster line published at short notice for a date that had none.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scheduleChanges(Employee $employee, Carbon $today): array
    {
        // Timestamps are stored in UTC while $today is a naive calendar date, so the
        // "announced recently" bound is taken from the clock, not from that date.
        $announcedAfter = now()->subDays(self::CHANGE_LOOKBACK_DAYS);
        $changes = collect();

        $swaps = ShiftSwapRequest::query()
            ->with(['requesterAssignment.shift', 'targetAssignment.shift'])
            ->where('status', 'approved')
            ->where('reviewed_at', '>=', $announcedAfter)
            ->where(fn ($query) => $query
                ->where('requester_employee_id', $employee->id)
                ->orWhere('target_employee_id', $employee->id))
            ->get();

        foreach ($swaps as $swap) {
            $isRequester = $swap->requester_employee_id === $employee->id;
            // Approval hands each side the other's assignment, so the slot they gave
            // up is the one their own request row still points at.
            $before = $isRequester ? $swap->requesterAssignment : $swap->targetAssignment;
            $after = $isRequester ? $swap->targetAssignment : $swap->requesterAssignment;

            if ($before === null || $after === null) {
                continue;
            }

            $changes->push([
                'type' => 'swap',
                'type_label' => 'Shift swap approved',
                'date' => $after->work_date->toDateString(),
                'date_label' => $after->work_date->format('D, M j, Y'),
                'from' => $before->shift?->name.' · '.$before->work_date->format('M j').' · '.$before->shift?->formatted_time,
                'to' => $after->shift?->name.' · '.$after->shift?->formatted_time,
                'reason' => $swap->reason,
                'announced' => $swap->reviewed_at?->diffForHumans(),
            ]);
        }

        $assignments = ScheduleAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereDate('work_date', '>=', $today->toDateString())
            ->whereDate('work_date', '<=', $today->copy()->addDays(self::UPCOMING_DAYS)->toDateString())
            ->get()
            ->keyBy(fn (ScheduleAssignment $assignment): string => $assignment->work_date->toDateString());

        $dayOffs = ScheduleDayOff::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $today->toDateString())
            ->where('created_at', '>=', $announcedAfter)
            ->get();

        foreach ($dayOffs as $dayOff) {
            $replaced = $assignments->get($dayOff->work_date->toDateString());

            if ($replaced === null) {
                continue;
            }

            $changes->push([
                'type' => 'rest_day',
                'type_label' => 'Changed to rest day',
                'date' => $dayOff->work_date->toDateString(),
                'date_label' => $dayOff->work_date->format('D, M j, Y'),
                'from' => $replaced->shift?->name.' · '.$replaced->shift?->formatted_time,
                'to' => 'Rest Day · No working hours',
                'reason' => $dayOff->notes ?: str($dayOff->source)->replace('_', ' ')->title()->value(),
                'announced' => $dayOff->created_at?->diffForHumans(),
            ]);
        }

        $shortNoticeFrom = now()->subDays(self::SHORT_NOTICE_ANNOUNCED_DAYS);
        $shortNoticeUntil = $today->copy()->addDays(self::SHORT_NOTICE_WORK_DAYS)->toDateString();

        foreach ($assignments as $date => $assignment) {
            if ($date > $shortNoticeUntil || $assignment->created_at < $shortNoticeFrom) {
                continue;
            }

            // The rest-day entry above already tells this date's story.
            if ($changes->contains(fn (array $change): bool => $change['date'] === $date)) {
                continue;
            }

            $changes->push([
                'type' => 'added',
                'type_label' => 'Shift added at short notice',
                'date' => $date,
                'date_label' => $assignment->work_date->format('D, M j, Y'),
                'from' => 'No shift assigned',
                'to' => $assignment->shift?->name.' · '.$assignment->shift?->formatted_time,
                'reason' => $assignment->notes ?: 'Staff coverage',
                'announced' => $assignment->created_at?->diffForHumans(),
            ]);
        }

        return $changes->sortBy('date')->take(self::CHANGE_LIMIT)->values()->all();
    }

    /**
     * This employee's own overtime for the month.
     *
     * Approved and pending are the two halves of the total, split on the attendance
     * record's approval status. Rejected overtime is in neither and is left out of
     * the total as well -- a supervisor has already decided those minutes do not
     * count, so carrying them in a headline figure would only mislead.
     *
     * @param  array<string, mixed>  $ledger
     * @return array<string, mixed>
     */
    private function overtime(array $ledger, Carbon $from, Carbon $to): array
    {
        $approved = 0;
        $pending = 0;
        $days = 0;

        foreach ($ledger['records'] as $date => $record) {
            if ($date < $from->toDateString() || $date > $to->toDateString() || $record->overtime_minutes < 1) {
                continue;
            }

            if ($record->approval_status === 'rejected') {
                continue;
            }

            if ($record->approval_status === 'approved') {
                $approved += $record->overtime_minutes;
            } else {
                $pending += $record->overtime_minutes;
            }

            $days++;
        }

        return [
            'month_label' => $from->format('F Y'),
            'total_hours' => $this->hours($approved + $pending),
            'approved_hours' => $this->hours($approved),
            'pending_hours' => $this->hours($pending),
            'total_minutes' => $approved + $pending,
            'pending_minutes' => $pending,
            'days' => $days,
            // Splits the meter; a month with no overtime draws an empty track.
            'approved_share' => $approved + $pending > 0 ? round($approved / ($approved + $pending) * 100, 1) : 0.0,
        ];
    }

    /**
     * Personal performance: five month-to-date figures, and the same attendance rate
     * plotted for the last six months so the current one has something to sit
     * against. Every figure is this employee's own; nothing is a ward average.
     *
     * @param  array<string, mixed>  $ledger
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function analytics(array $ledger, Carbon $today, array $summary): array
    {
        $overtimeMinutes = 0;
        foreach ($ledger['records'] as $date => $record) {
            if ($date >= $today->copy()->startOfMonth()->toDateString() && $record->approval_status !== 'rejected') {
                $overtimeMinutes += $record->overtime_minutes;
            }
        }

        $trend = [];
        for ($offset = self::TREND_MONTHS - 1; $offset >= 0; $offset--) {
            $monthStart = $today->copy()->startOfMonth()->subMonthsNoOverflow($offset);
            $monthEnd = $monthStart->copy()->endOfMonth();
            $month = $this->monthSummary(
                $ledger,
                $monthStart,
                $monthEnd->greaterThan($today) ? $today : $monthEnd,
                $today,
            );

            $trend[] = [
                'label' => $monthStart->format('M'),
                'full_label' => $monthStart->format('F Y'),
                'rate' => $month['rate'],
                'present' => $month['present'],
                'late' => $month['late'],
                'absent' => $month['absent'],
                'expected' => $month['expected'],
                'tracked' => $month['expected'] > 0,
                'is_current' => $offset === 0,
            ];
        }

        return [
            'range_label' => $summary['range_label'],
            'metrics' => [
                ['key' => 'attendance_rate', 'label' => 'Attendance rate', 'value' => $this->percent($summary['rate']), 'detail' => ($summary['present'] + $summary['late']).' of '.$summary['expected'].' expected '.str('day')->plural($summary['expected'])],
                ['key' => 'punctuality', 'label' => 'Punctuality rate', 'value' => $this->percent($summary['punctuality']), 'detail' => $summary['late'].' late '.str('arrival')->plural($summary['late']).' this month'],
                ['key' => 'worked_hours', 'label' => 'Total hours worked', 'value' => $this->number($summary['worked_hours']).'h', 'detail' => 'Recorded by the attendance system'],
                ['key' => 'overtime', 'label' => 'Overtime', 'value' => $this->hours($overtimeMinutes), 'detail' => 'Approved and pending combined'],
                ['key' => 'leave_used', 'label' => 'Leave used', 'value' => $summary['leave'].' '.str('day')->plural($summary['leave']), 'detail' => 'Approved leave taken this month'],
            ],
            'trend' => $trend,
            'trend_tracked' => collect($trend)->contains(fn (array $month): bool => $month['tracked']),
        ];
    }

    /**
     * Every dated fact about this employee inside a window, read once.
     *
     * Attendance, roster, rest days, and leave each cost one query no matter how
     * many widgets consume them, and every consumer keys off the same calendar
     * date strings, which is what stops the tallies drifting apart.
     *
     * @return array{
     *     records: Collection<string, AttendanceRecord>,
     *     assignments: Collection<string, array<string, mixed>>,
     *     day_off_dates: Collection<int, string>,
     *     leave_dates: Collection<int, string>,
     *     worked_minutes_by_month: Collection<string, int>,
     * }
     */
    private function ledger(Employee $employee, Carbon $from, Carbon $to): array
    {
        $records = AttendanceRecord::query()
            ->with(['checkInBiometricDevice:id,name', 'checkOutBiometricDevice:id,name'])
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->get()
            ->keyBy(fn (AttendanceRecord $record): string => $record->attendance_date->toDateString());

        return [
            'records' => $records,
            'assignments' => $this->assignmentsBetween($employee, $from, $to),
            'day_off_dates' => $this->dayOffDatesBetween($employee, $from, $to),
            'leave_dates' => $this->leaveDatesBetween($employee, $from, $to),
            'worked_minutes_by_month' => $records
                ->groupBy(fn (AttendanceRecord $record): string => $record->attendance_date->format('Y-m'))
                ->map(fn (Collection $month): int => (int) $month->sum('worked_minutes')),
        ];
    }

    /**
     * Scheduled assignments keyed by calendar date, flattened to the shift facts the
     * widgets read so no view has to touch a model.
     *
     * @return Collection<string, array<string, mixed>>
     */
    private function assignmentsBetween(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        return ScheduleAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $to->toDateString())
            ->orderBy('work_date')
            ->get()
            ->keyBy(fn (ScheduleAssignment $assignment): string => $assignment->work_date->toDateString())
            ->map(fn (ScheduleAssignment $assignment): array => [
                'name' => $assignment->shift?->name ?? 'Shift',
                'start_time' => $assignment->shift?->start_time ?? '00:00',
                'formatted_time' => $assignment->shift?->formatted_time ?? '—',
                'color' => $assignment->shift?->color ?: '#176b43',
                'crosses_midnight' => (bool) $assignment->shift?->crosses_midnight,
                'notes' => $assignment->notes,
            ]);
    }

    /** @return Collection<int, string> */
    private function dayOffDatesBetween(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        return ScheduleDayOff::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $to->toDateString())
            ->pluck('work_date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->unique()
            ->values();
    }

    /**
     * Approved leave expanded into the calendar dates it covers, clipped to the
     * window so a request that started last month spends only its own days here.
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

    /** Minutes as the "8h 30m" shape the attendance module already uses. */
    private function hours(int $minutes): string
    {
        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
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
}
