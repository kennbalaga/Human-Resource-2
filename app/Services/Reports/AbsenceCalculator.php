<?php

namespace App\Services\Reports;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * How many shifts went unworked over a period.
 *
 * The attendance report used to answer this only when the range was a single
 * day, and printed an em dash for every other range -- which is every range
 * anyone actually runs. Worse, on the one day it did answer, it counted every
 * employee currently on the payroll against a date in the past and subtracted
 * nothing for approved leave, so a ward with three nurses on booked annual
 * leave read as three absences and someone had to know, from memory, not to
 * act on it.
 *
 * The rule here is that an absence is an expected shift with no attendance
 * record against it, and the roster decides what was expected:
 *
 *  - If the employee has published assignments in the period, those are the
 *    expected days. This is exact, and it is what a rostered hospital means by
 *    the word -- a night-shift nurse rostered Saturday is not absent on the
 *    Monday they were never asked to work.
 *  - If they have none -- the roster for that stretch was never published, or
 *    they sit on a fixed office pattern -- it falls back to weekdays, matching
 *    the convention Workforce Analytics already uses for its attendance rate,
 *    less any day off recorded against them.
 *
 * Approved leave is deducted either way, and nobody is counted before the date
 * they were hired.
 *
 * The one thing it cannot do is exclude somebody who has since left: the
 * employees table records `employment_status` and `hire_date` but no
 * separation date, so a period before a leaver's last day still measures them
 * against today's roster. That needs a schema change, not arithmetic, and
 * until then this is stated rather than silently approximated.
 */
class AbsenceCalculator
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function forPeriod(array $filters, ?User $user): int
    {
        $from = Carbon::parse($filters['date_from'])->startOfDay();
        // End of day, not start. The query bounds below are then taken as a
        // bare date and a full datetime respectively, because `attendance_date`
        // and `work_date` are serialised differently -- Report::rangeStart()
        // has the long version.
        $to = Carbon::parse($filters['date_to'])->endOfDay();

        $employees = $this->employeesInScope($filters, $user);

        if ($employees->isEmpty()) {
            return 0;
        }

        $employeeIds = $employees->pluck('id');
        $scheduled = $this->countByEmployee(
            ScheduleAssignment::query()
                ->where('status', 'scheduled')
                ->whereBetween('work_date', [$from->toDateString(), $to->toDateTimeString()])
                ->whereIn('employee_id', $employeeIds),
            'work_date',
        );
        $attended = $this->countByEmployee(
            AttendanceRecord::query()
                ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateTimeString()])
                ->whereIn('employee_id', $employeeIds),
            'attendance_date',
        );
        $daysOff = $this->countByEmployee(
            ScheduleDayOff::query()
                ->whereBetween('work_date', [$from->toDateString(), $to->toDateTimeString()])
                ->whereIn('employee_id', $employeeIds),
            'work_date',
        );
        $leaveDays = $this->approvedLeaveDays($employeeIds, $from, $to);

        return $employees->sum(function (Employee $employee) use ($from, $to, $scheduled, $attended, $daysOff, $leaveDays): int {
            $rostered = (int) $scheduled->get($employee->id, 0);

            $expected = $rostered > 0
                ? $rostered
                : $this->weekdaysFor($employee, $from, $to) - (int) $daysOff->get($employee->id, 0);

            $expected -= (int) round((float) $leaveDays->get($employee->id, 0.0));

            return max(0, $expected - (int) $attended->get($employee->id, 0));
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Employee>
     */
    private function employeesInScope(array $filters, ?User $user): Collection
    {
        return Employee::query()
            ->visibleTo($user)
            ->where('employment_status', 'active')
            ->when($filters['department_id'] ?? null, fn (Builder $query, $departmentId) => $query->where('department_id', $departmentId))
            ->when($filters['employee_id'] ?? null, fn (Builder $query, $employeeId) => $query->whereKey($employeeId))
            ->get(['id', 'hire_date']);
    }

    /**
     * Distinct dates per employee, in one grouped query rather than one query
     * per employee.
     *
     * @return Collection<int, int>
     */
    private function countByEmployee(Builder $query, string $dateColumn): Collection
    {
        return $query
            ->selectRaw("employee_id, count(distinct {$dateColumn}) as day_count")
            ->groupBy('employee_id')
            ->pluck('day_count', 'employee_id');
    }

    /**
     * Approved leave, clamped to the reporting period so a fortnight that
     * straddles the range contributes only the days inside it.
     *
     * @return Collection<int, float>
     */
    private function approvedLeaveDays(Collection $employeeIds, Carbon $from, Carbon $to): Collection
    {
        return LeaveRequest::query()
            ->where('status', 'approved')
            ->whereIn('employee_id', $employeeIds)
            ->where('start_date', '<=', $to->toDateTimeString())
            ->where('end_date', '>=', $from->toDateString())
            ->get(['employee_id', 'start_date', 'end_date'])
            ->groupBy('employee_id')
            ->map(fn (Collection $requests) => $requests->sum(function (LeaveRequest $leave) use ($from, $to): float {
                $start = $leave->start_date->greaterThan($from) ? $leave->start_date : $from;
                $end = $leave->end_date->lessThan($to) ? $leave->end_date : $to;

                return $start->greaterThan($end) ? 0.0 : (float) ($start->diffInDays($end) + 1);
            }));
    }

    /**
     * Weekdays in the period, never counting a date before the employee was
     * hired.
     */
    private function weekdaysFor(Employee $employee, Carbon $from, Carbon $to): int
    {
        $start = $employee->hire_date && $employee->hire_date->greaterThan($from)
            ? $employee->hire_date->copy()->startOfDay()
            : $from;

        if ($start->greaterThan($to)) {
            return 0;
        }

        return collect(CarbonPeriod::create($start, $to))
            ->reject(fn ($date) => Carbon::instance($date)->isWeekend())
            ->count();
    }
}
