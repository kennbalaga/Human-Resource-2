<?php

namespace App\Reports;

use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Approved hours per pay period -- the report payroll asks for.
 *
 * The timesheets screen could already export this, but only as the whole
 * unfiltered list from its own CSV writer. Declared as a report it gains the
 * same date range, department scoping, three formats and audit trail as
 * everything else under Insights, and stops being a second, slightly different
 * idea of what a timesheet export contains.
 */
class TimesheetReport extends Report
{
    public function key(): string
    {
        return 'timesheet';
    }

    public function label(): string
    {
        return 'Timesheets';
    }

    public function description(): string
    {
        return 'Regular, overtime, late and undertime minutes per pay period, ready for payroll.';
    }

    public function icon(): string
    {
        return 'timesheet';
    }

    public function heading(): string
    {
        return 'Timesheet Reports';
    }

    public function filters(): array
    {
        return ['department_id', 'employee_id', 'timesheet_status'];
    }

    public function rangeLabel(): string
    {
        return 'Periods overlapping';
    }

    public function columns(): array
    {
        return [
            ReportColumn::make('Period Start', fn (Timesheet $r) => $r->period_start->toDateString()),
            ReportColumn::make('Period End', fn (Timesheet $r) => $r->period_end->toDateString()),
            ReportColumn::make('Employee ID', fn (Timesheet $r) => $r->employee->employee_number),
            ReportColumn::make('Employee', fn (Timesheet $r) => $r->employee->full_name),
            ReportColumn::make('Department', fn (Timesheet $r) => $r->employee->department?->name),
            ReportColumn::make('Status', fn (Timesheet $r) => $r->status),
            ReportColumn::make('Regular Minutes', fn (Timesheet $r) => $r->regular_minutes)->numeric(),
            ReportColumn::make('Overtime Minutes', fn (Timesheet $r) => $r->overtime_minutes)->numeric(),
            ReportColumn::make('Late Minutes', fn (Timesheet $r) => $r->late_minutes)->numeric(),
            ReportColumn::make('Undertime Minutes', fn (Timesheet $r) => $r->undertime_minutes)->numeric(),
            ReportColumn::make('Submitted On', fn (Timesheet $r) => $r->submitted_at?->toDateString())->wide(),
            ReportColumn::make('Reviewed By', fn (Timesheet $r) => $r->reviewer?->name)->wide(),
        ];
    }

    public function query(array $filters): Builder
    {
        return Timesheet::query()
            ->with(['employee.department', 'reviewer'])
            ->where('period_start', '<=', $this->rangeEnd($filters))
            ->where('period_end', '>=', $this->rangeStart($filters))
            ->when($filters['department_id'] ?? null, fn (Builder $query, $departmentId) => $query
                ->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('department_id', $departmentId)))
            ->when($filters['employee_id'] ?? null, fn (Builder $query, $employeeId) => $query
                ->where('employee_id', $employeeId))
            ->when($filters['timesheet_status'] ?? null, fn (Builder $query, $status) => $query
                ->where('status', $status))
            ->latest('period_start');
    }

    public function applyScope(Builder $query, ?User $user): Builder
    {
        return Employee::constrainRelatedQuery($query, $user);
    }

    public function summary(array $filters, ?User $user): array
    {
        $totals = $this->scopedQuery($filters, $user)
            ->reorder()
            ->toBase()
            ->selectRaw('count(*) as sheets')
            ->selectRaw("sum(case when status = 'approved' then 1 else 0 end) as approved")
            ->selectRaw("sum(case when status = 'submitted' then 1 else 0 end) as submitted")
            ->selectRaw('sum(regular_minutes) as regular_minutes')
            ->selectRaw('sum(overtime_minutes) as overtime_minutes')
            ->selectRaw('count(distinct employee_id) as employees')
            ->first();

        return [
            AttendanceReport::tile('Timesheets', number_format((int) ($totals->sheets ?? 0)), 'timesheet', 'blue'),
            AttendanceReport::tile('Approved', number_format((int) ($totals->approved ?? 0)), 'check-circle', 'green'),
            AttendanceReport::tile('Awaiting review', number_format((int) ($totals->submitted ?? 0)), 'clock', 'amber'),
            AttendanceReport::tile('Regular hours', AttendanceReport::hours((int) ($totals->regular_minutes ?? 0)), 'clock', 'violet'),
            AttendanceReport::tile('Overtime', AttendanceReport::hours((int) ($totals->overtime_minutes ?? 0)), 'arrow-up', 'green'),
            AttendanceReport::tile('Employees', number_format((int) ($totals->employees ?? 0)), 'users', 'blue'),
        ];
    }
}
