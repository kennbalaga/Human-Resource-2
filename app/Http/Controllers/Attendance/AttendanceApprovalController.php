<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Services\Integrations\SafeIntegrationDispatcher;
use App\Services\TimesheetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AttendanceApprovalController extends Controller
{
    public function approve(Request $request, AttendanceRecord $attendanceRecord, TimesheetService $service, SafeIntegrationDispatcher $integrations): RedirectResponse
    {
        $this->authorizeManager($request);
        $timesheet = $service->approveAttendance($attendanceRecord, $request->user());
        $integrations->zapier('attendance.approved', [
            'attendance_id' => $attendanceRecord->id,
            'employee_id' => $attendanceRecord->employee_id,
            'attendance_date' => $attendanceRecord->attendance_date->toDateString(),
            'timesheet_id' => $timesheet->id,
        ]);

        return back()->with('success', 'Attendance approved and added to the employee timesheet.');
    }

    public function reject(Request $request, AttendanceRecord $attendanceRecord, TimesheetService $service): RedirectResponse
    {
        $this->authorizeManager($request);
        $validated = $request->validate(['rejection_reason' => ['required', 'string', 'min:5', 'max:500']]);
        $service->rejectAttendance($attendanceRecord, $request->user(), $validated['rejection_reason']);

        return back()->with('success', 'Attendance record rejected.');
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($request->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])->exists(), 403);
    }
}
