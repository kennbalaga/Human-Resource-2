<?php

namespace App\Reports;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Leave filed, approved and refused over a period.
 *
 * Leave figures existed before this report, but only as two totals on the
 * Workforce Analytics page -- approved days for the whole hospital and a
 * per-department column. Neither can answer the question a ward manager
 * actually arrives with, which is who was off, on what, and who signed it.
 */
class LeaveReport extends Report
{
    public function key(): string
    {
        return 'leave';
    }

    public function label(): string
    {
        return 'Leave';
    }

    public function description(): string
    {
        return 'Requests, approvals and refusals by employee, type and reviewer.';
    }

    public function icon(): string
    {
        return 'leave';
    }

    public function filters(): array
    {
        return ['department_id', 'employee_id', 'leave_status', 'leave_type_id'];
    }

    public function rangeLabel(): string
    {
        return 'Leave overlapping';
    }

    public function columns(): array
    {
        return [
            ReportColumn::make('Employee ID', fn (LeaveRequest $r) => $r->employee->employee_number),
            ReportColumn::make('Employee', fn (LeaveRequest $r) => $r->employee->full_name),
            ReportColumn::make('Department', fn (LeaveRequest $r) => $r->employee->department?->name),
            ReportColumn::make('Leave Type', fn (LeaveRequest $r) => $r->leaveType?->name),
            ReportColumn::make('Start', fn (LeaveRequest $r) => $r->start_date->toDateString()),
            ReportColumn::make('End', fn (LeaveRequest $r) => $r->end_date->toDateString()),
            ReportColumn::make('Days', fn (LeaveRequest $r) => (float) $r->requested_days)->numeric(),
            ReportColumn::make('Status', fn (LeaveRequest $r) => $r->status),
            ReportColumn::make('Filed On', fn (LeaveRequest $r) => $r->created_at?->toDateString()),
            ReportColumn::make('Reviewed By', fn (LeaveRequest $r) => $r->reviewer?->name)->wide(),
            ReportColumn::make('Reviewed On', fn (LeaveRequest $r) => $r->reviewed_at?->toDateString())->wide(),
            ReportColumn::make('Reason', fn (LeaveRequest $r) => $r->reason)->wide(),
        ];
    }

    public function query(array $filters): Builder
    {
        return LeaveRequest::query()
            ->with(['employee.department', 'leaveType', 'reviewer'])
            // Overlap, not containment: a fortnight that starts in the previous
            // month is still leave taken during this one, and a report that
            // asked for start_date BETWEEN would drop it from both months.
            ->where('start_date', '<=', $this->rangeEnd($filters))
            ->where('end_date', '>=', $this->rangeStart($filters))
            ->when($filters['department_id'] ?? null, fn (Builder $query, $departmentId) => $query
                ->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('department_id', $departmentId)))
            ->when($filters['employee_id'] ?? null, fn (Builder $query, $employeeId) => $query
                ->where('employee_id', $employeeId))
            ->when($filters['leave_status'] ?? null, fn (Builder $query, $status) => $query
                ->where('status', $status))
            ->when($filters['leave_type_id'] ?? null, fn (Builder $query, $typeId) => $query
                ->where('leave_type_id', $typeId))
            ->latest('start_date');
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
            ->selectRaw('count(*) as requests')
            ->selectRaw("sum(case when status = 'approved' then 1 else 0 end) as approved")
            ->selectRaw("sum(case when status = 'pending' then 1 else 0 end) as pending")
            ->selectRaw("sum(case when status = 'rejected' then 1 else 0 end) as rejected")
            ->selectRaw("sum(case when status = 'approved' then requested_days else 0 end) as approved_days")
            ->selectRaw('count(distinct employee_id) as employees')
            ->first();

        return [
            AttendanceReport::tile('Requests', number_format((int) ($totals->requests ?? 0)), 'leave', 'blue'),
            AttendanceReport::tile('Approved', number_format((int) ($totals->approved ?? 0)), 'check-circle', 'green'),
            AttendanceReport::tile('Pending', number_format((int) ($totals->pending ?? 0)), 'clock', 'amber'),
            AttendanceReport::tile('Rejected', number_format((int) ($totals->rejected ?? 0)), 'close', 'red'),
            AttendanceReport::tile('Approved days', number_format((float) ($totals->approved_days ?? 0), 1), 'calendar', 'violet'),
            AttendanceReport::tile('Employees', number_format((int) ($totals->employees ?? 0)), 'users', 'blue'),
        ];
    }
}
