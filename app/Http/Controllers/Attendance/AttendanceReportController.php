<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceReportRequest;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceReportController extends Controller
{
    public function index(AttendanceReportRequest $request): View
    {
        $filters = $request->validated();
        $query = $this->reportQuery($filters);

        $summary = [
            'records' => (clone $query)->count(),
            'present' => (clone $query)->where('status', 'present')->count(),
            'late' => (clone $query)->where('status', 'late')->count(),
            'worked_minutes' => (int) (clone $query)->sum('worked_minutes'),
            'overtime_minutes' => (int) (clone $query)->sum('overtime_minutes'),
            'absent' => null,
            'pending_approval' => (clone $query)->where('approval_status', 'pending')->count(),
        ];

        if ($filters['date_from'] === $filters['date_to']) {
            $employeesQuery = Employee::query()->where('employment_status', 'active');
            if (! empty($filters['department_id'])) {
                $employeesQuery->where('department_id', $filters['department_id']);
            }

            $summary['absent'] = max(0, $employeesQuery->count() - (clone $query)->distinct('employee_id')->count('employee_id'));
        }

        return view('attendance.reports.index', [
            'records' => $query->latest('attendance_date')->latest('check_in_at')->paginate(20)->withQueryString(),
            'summary' => $summary,
            'filters' => $filters,
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(),
            'employees' => Employee::query()->where('employment_status', 'active')->orderBy('last_name')->get(),
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
            'notifications' => collect(),
        ]);
    }

    public function export(AttendanceReportRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $records = $this->reportQuery($filters)->latest('attendance_date')->cursor();
        $filename = "attendance-{$filters['date_from']}-to-{$filters['date_to']}.csv";

        return response()->streamDownload(function () use ($records): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'Date', 'Employee ID', 'Employee', 'Department', 'Check In', 'Check Out',
                'Status', 'Late Minutes', 'Worked Minutes', 'Undertime Minutes', 'Overtime Minutes',
            ]);

            foreach ($records as $record) {
                fputcsv($output, [
                    $record->attendance_date->toDateString(),
                    $record->employee->employee_number,
                    $record->employee->full_name,
                    $record->employee->department?->name,
                    $record->check_in_at?->timezone($record->officeLocation?->timezone ?? 'Asia/Manila')->format('Y-m-d H:i:s'),
                    $record->check_out_at?->timezone($record->officeLocation?->timezone ?? 'Asia/Manila')->format('Y-m-d H:i:s'),
                    $record->status,
                    $record->late_minutes,
                    $record->worked_minutes,
                    $record->undertime_minutes,
                    $record->overtime_minutes,
                ]);
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function reportQuery(array $filters): Builder
    {
        return AttendanceRecord::query()
            ->with(['employee.user', 'employee.department', 'employee.position', 'officeLocation'])
            ->whereBetween('attendance_date', [$filters['date_from'], $filters['date_to']])
            ->when($filters['department_id'] ?? null, fn (Builder $query, $departmentId) => $query
                ->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('department_id', $departmentId)))
            ->when($filters['employee_id'] ?? null, fn (Builder $query, $employeeId) => $query
                ->where('employee_id', $employeeId))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query
                ->where('status', $status))
            ->when($filters['approval_status'] ?? null, fn (Builder $query, $status) => $query
                ->where('approval_status', $status));
    }
}
