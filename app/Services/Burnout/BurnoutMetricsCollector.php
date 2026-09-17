<?php

namespace App\Services\Burnout;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Services\ReferenceDataCache;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Reads the workload figures a burnout assessment is scored on, for many
 * employees at once.
 *
 * The cost of this system is round trips, not rows, so the whole population is
 * read in three queries -- attendance, the roster, approved leave -- and every
 * figure is then worked out in memory. Asking per employee, the way candidate
 * scoring does for a handful of names, would cost a hospital-wide assessment
 * hundreds of trips.
 *
 * Two windows are measured for each person: the current one, ending yesterday,
 * and the one just before it. Today is left out because its shifts have not
 * been worked yet.
 *
 * A day counts as worked when there is a punch for it, or when it was rostered
 * and nobody recorded it. The second half overstates the load of someone who
 * simply did not come in, which is the safer mistake here: a missing punch is
 * common, and an indicator that reads a capture gap as rest would miss exactly
 * the people it exists for.
 */
class BurnoutMetricsCollector
{
    public function __construct(
        private readonly ScheduleService $schedules,
        private readonly ReferenceDataCache $reference,
    ) {}

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array{current: array<string, float|int>, previous: array<string, float|int>|null}>
     */
    public function collect(Collection $employees, CarbonInterface $asOf): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        $timezone = config('schedule.timezone');
        $window = max(7, (int) config('burnout.window_days'));
        $leaveLookback = max(1, (int) config('burnout.leave_lookback_days'));
        $leaveHorizon = max($leaveLookback, (int) config('burnout.factors.days_since_leave.to'));

        $asOf = Carbon::parse($asOf->toDateString(), $timezone)->startOfDay();
        $currentEnd = $asOf->copy()->subDay();
        $currentStart = $asOf->copy()->subDays($window);
        $previousEnd = $currentStart->copy()->subDay();
        $previousStart = $currentStart->copy()->subDays($window);

        $ids = $employees->pluck('id')->all();

        // attendance_date is stored with a midnight time, so the upper bound
        // runs to the end of the last day. See Report::rangeEnd().
        $attendance = AttendanceRecord::query()
            ->select(['id', 'employee_id', 'attendance_date', 'check_in_at', 'check_out_at', 'worked_minutes', 'overtime_minutes'])
            ->whereIn('employee_id', $ids)
            ->where('attendance_date', '>=', $previousStart->toDateString())
            ->where('attendance_date', '<=', $currentEnd->copy()->endOfDay()->toDateTimeString())
            ->where('approval_status', '!=', 'rejected')
            ->get()
            ->groupBy('employee_id');

        $assignments = ScheduleAssignment::query()
            ->select(['id', 'employee_id', 'shift_id', 'work_date'])
            ->whereIn('employee_id', $ids)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [$previousStart->toDateString(), $currentEnd->toDateString()])
            ->get();
        // A retired shift still describes hours somebody worked, so it is
        // looked up with the soft-deleted rows included.
        $shifts = $this->reference->shifts();
        $retired = $assignments->pluck('shift_id')->unique()->diff($shifts->keys());
        if ($retired->isNotEmpty()) {
            $shifts = $shifts->union(
                Shift::query()->withTrashed()->whereKey($retired->all())->get()->keyBy('id')
            );
        }
        $assignments->each(fn (ScheduleAssignment $assignment) => $assignment->setRelation('shift', $shifts->get($assignment->shift_id)));
        $assignments = $assignments->groupBy('employee_id');

        $leaveTypes = $this->reference->leaveTypes();
        $unplannedTypeIds = $leaveTypes
            ->filter(fn (LeaveType $type) => in_array($type->code, config('burnout.unplanned_leave_codes', []), true))
            ->keys()
            ->all();
        $leaves = LeaveRequest::query()
            ->select(['id', 'employee_id', 'leave_type_id', 'start_date', 'end_date'])
            ->whereIn('employee_id', $ids)
            ->where('status', 'approved')
            ->whereDate('end_date', '>=', $previousEnd->copy()->subDays($leaveHorizon)->toDateString())
            ->whereDate('start_date', '<=', $currentEnd->toDateString())
            ->get()
            ->groupBy('employee_id');

        $minimumRestMinutes = max(0, (int) config('schedule.minimum_rest_hours')) * 60;
        $settings = compact('minimumRestMinutes', 'unplannedTypeIds', 'leaveLookback', 'leaveHorizon');

        $results = [];

        foreach ($employees as $employee) {
            $days = $this->dutyDays(
                $attendance->get($employee->id, collect()),
                $assignments->get($employee->id, collect()),
            );
            $employeeLeaves = $leaves->get($employee->id, collect());
            $hired = $employee->hire_date !== null
                ? Carbon::parse($employee->hire_date->toDateString(), $timezone)->startOfDay()
                : null;

            $results[$employee->id] = [
                'current' => $this->windowMetrics($days, $employeeLeaves, $currentStart, $currentEnd, $hired, $settings),
                // Somebody hired partway through the earlier window has no full
                // window to compare against; reading it anyway would report
                // every new starter as rising.
                'previous' => $hired !== null && $hired->greaterThan($previousStart)
                    ? null
                    : $this->windowMetrics($days, $employeeLeaves, $previousStart, $previousEnd, $hired, $settings),
            ];
        }

        return $results;
    }

    /**
     * One entry per worked date: its minutes, overtime, whether it was a night,
     * and the clock intervals it covered.
     *
     * @param  Collection<int, AttendanceRecord>  $records
     * @param  Collection<int, ScheduleAssignment>  $assignments
     * @return Collection<string, array{minutes: int, overtime: int, night: bool, intervals: array<int, array{0: CarbonInterface, 1: CarbonInterface}>}>
     */
    private function dutyDays(Collection $records, Collection $assignments): Collection
    {
        $rostered = $assignments
            ->filter(fn (ScheduleAssignment $assignment) => $assignment->shift !== null)
            ->groupBy(fn (ScheduleAssignment $assignment) => $assignment->work_date->toDateString());
        $days = collect();

        foreach ($records as $record) {
            if ($record->check_in_at === null) {
                continue;
            }

            $date = $record->attendance_date->toDateString();
            $scheduled = $rostered->get($date, collect());
            $minutes = (int) $record->worked_minutes;

            // Still clocked in, or closed without a computed figure: the
            // rostered length is the best estimate of the day.
            if ($minutes <= 0) {
                $minutes = (int) $scheduled->sum(fn (ScheduleAssignment $assignment) => $assignment->shift->duration_minutes);
            }

            $intervals = $record->check_out_at !== null && $record->check_out_at->greaterThan($record->check_in_at)
                ? [[$record->check_in_at, $record->check_out_at]]
                : $this->rosteredIntervals($scheduled, $date);

            $days->put($date, [
                'minutes' => $minutes,
                'overtime' => (int) $record->overtime_minutes,
                'night' => $scheduled->isNotEmpty()
                    ? $scheduled->contains(fn (ScheduleAssignment $assignment) => $assignment->shift->is_night_shift)
                    : $this->startsAtNight($record->check_in_at),
                'intervals' => $intervals,
            ]);
        }

        foreach ($rostered as $date => $scheduled) {
            if ($days->has($date)) {
                continue;
            }

            $days->put($date, [
                'minutes' => (int) $scheduled->sum(fn (ScheduleAssignment $assignment) => $assignment->shift->duration_minutes),
                'overtime' => 0,
                'night' => $scheduled->contains(fn (ScheduleAssignment $assignment) => $assignment->shift->is_night_shift),
                'intervals' => $this->rosteredIntervals($scheduled, $date),
            ]);
        }

        return $days;
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $days
     * @param  Collection<int, LeaveRequest>  $leaves
     * @param  array{minimumRestMinutes: int, unplannedTypeIds: array<int, int>, leaveLookback: int, leaveHorizon: int}  $settings
     * @return array<string, float|int>
     */
    private function windowMetrics(Collection $days, Collection $leaves, Carbon $start, Carbon $end, ?Carbon $hired, array $settings): array
    {
        $from = $start->toDateString();
        $to = $end->toDateString();
        $inWindow = $days
            ->filter(fn (array $day, string $date) => $date >= $from && $date <= $to)
            ->sortKeys();
        $weeks = ((int) round(abs($start->diffInDays($end))) + 1) / 7;

        return [
            'weekly_hours' => round($inWindow->sum('minutes') / 60 / $weeks, 1),
            'overtime_hours' => round($inWindow->sum('overtime') / 60, 1),
            'work_streak' => $this->longestStreak($inWindow->keys()),
            'night_shifts' => $inWindow->where('night', true)->count(),
            'short_rest' => $this->quickReturns($inWindow, $settings['minimumRestMinutes']),
            'days_since_leave' => $this->daysSinceLeave($leaves, $end, $hired, $settings['leaveHorizon']),
            'unplanned_leave' => $leaves
                ->filter(fn (LeaveRequest $leave) => in_array((int) $leave->leave_type_id, $settings['unplannedTypeIds'], true)
                    && $leave->start_date->toDateString() <= $to
                    && $leave->start_date->toDateString() > $end->copy()->subDays($settings['leaveLookback'])->toDateString())
                ->count(),
            // Context for the reader, not a scored factor.
            'duty_days' => $inWindow->count(),
        ];
    }

    /** @param  Collection<int, string>  $dates  Sorted calendar dates */
    private function longestStreak(Collection $dates): int
    {
        $longest = 0;
        $run = 0;
        $previous = null;

        foreach ($dates as $date) {
            $run = $previous !== null && Carbon::parse($previous)->addDay()->toDateString() === $date ? $run + 1 : 1;
            $longest = max($longest, $run);
            $previous = $date;
        }

        return $longest;
    }

    /**
     * Times a duty began less than the minimum rest after the previous one
     * ended. Overlapping intervals are a roster conflict, not a short rest, and
     * are left to the conflict checks.
     *
     * @param  Collection<string, array<string, mixed>>  $days
     */
    private function quickReturns(Collection $days, int $minimumRestMinutes): int
    {
        if ($minimumRestMinutes <= 0) {
            return 0;
        }

        $intervals = $days
            ->flatMap(fn (array $day) => $day['intervals'])
            ->sortBy(fn (array $interval) => $interval[0]->getTimestamp())
            ->values();
        $count = 0;
        $latestEnd = null;

        foreach ($intervals as [$start, $end]) {
            if ($latestEnd !== null) {
                $gap = ($start->getTimestamp() - $latestEnd) / 60;

                if ($gap >= 0 && $gap < $minimumRestMinutes) {
                    $count++;
                }
            }

            $latestEnd = max($latestEnd ?? 0, $end->getTimestamp());
        }

        return $count;
    }

    /** @param  Collection<int, LeaveRequest>  $leaves */
    private function daysSinceLeave(Collection $leaves, Carbon $end, ?Carbon $hired, int $horizon): int
    {
        $lastEnd = $leaves
            ->filter(fn (LeaveRequest $leave) => $leave->start_date->toDateString() <= $end->toDateString())
            ->map(fn (LeaveRequest $leave) => $leave->end_date->toDateString())
            ->max();

        if ($lastEnd !== null) {
            return $lastEnd >= $end->toDateString()
                ? 0
                : min($horizon, (int) round(abs(Carbon::parse($lastEnd, $end->getTimezone())->diffInDays($end))));
        }

        // No leave on record inside the horizon. Someone who only started last
        // month has not gone long without a break; they have simply not been
        // here long.
        if ($hired !== null) {
            return $hired->greaterThan($end) ? 0 : min($horizon, (int) round($hired->diffInDays($end)));
        }

        return $horizon;
    }

    /**
     * @param  Collection<int, ScheduleAssignment>  $scheduled
     * @return array<int, array{0: CarbonInterface, 1: CarbonInterface}>
     */
    private function rosteredIntervals(Collection $scheduled, string $date): array
    {
        return $scheduled
            ->map(fn (ScheduleAssignment $assignment) => $this->schedules->intervalFor($assignment->shift, $date))
            ->values()
            ->all();
    }

    /** Mirrors Shift::getIsNightShiftAttribute() for a punch with no shift behind it. */
    private function startsAtNight(CarbonInterface $checkIn): bool
    {
        $hour = (int) $checkIn->copy()->setTimezone(config('schedule.timezone'))->format('G');

        return $hour >= 18 || $hour < 6;
    }
}
