<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesWorkforce;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\ScheduleAssignment;
use App\Models\Timesheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    use AuthorizesWorkforce;

    public function index(Request $request): JsonResponse
    {
        $this->requireManager($request->user());
        abort_unless($request->user()->tokenCan('analytics:read'), 403);
        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);
        $employeeIds = Employee::query()->where('employment_status', 'active')
            ->when($validated['department_id'] ?? null, fn ($query, $id) => $query->where('department_id', $id))->pluck('id');
        $attendance = AttendanceRecord::query()->whereIn('employee_id', $employeeIds)->whereBetween('attendance_date', [$validated['date_from'], $validated['date_to']]);

        return response()->json(['data' => [
            'period' => ['from' => $validated['date_from'], 'to' => $validated['date_to']],
            'active_headcount' => $employeeIds->count(),
            'attendance_records' => (clone $attendance)->count(),
            'worked_hours' => round((clone $attendance)->sum('worked_minutes') / 60, 1),
            'overtime_hours' => round((clone $attendance)->sum('overtime_minutes') / 60, 1),
            'late_events' => (clone $attendance)->where('status', 'late')->count(),
            'approved_leave_days' => (float) LeaveRequest::query()->whereIn('employee_id', $employeeIds)->where('status', 'approved')->whereDate('start_date', '<=', $validated['date_to'])->whereDate('end_date', '>=', $validated['date_from'])->sum('requested_days'),
            'scheduled_shifts' => ScheduleAssignment::query()->whereIn('employee_id', $employeeIds)->whereBetween('work_date', [$validated['date_from'], $validated['date_to']])->count(),
            'approved_timesheets' => Timesheet::query()->whereIn('employee_id', $employeeIds)->where('status', 'approved')->whereDate('period_end', '>=', $validated['date_from'])->whereDate('period_start', '<=', $validated['date_to'])->count(),
        ]]);
    }
}
