<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Services\TimesheetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AttendanceApprovalController extends Controller
{
    public function approve(Request $request, AttendanceRecord $attendanceRecord, TimesheetService $service): RedirectResponse
    {
        $this->authorizeManager($request);
        $service->approveAttendance($attendanceRecord, $request->user());

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
        abort_unless($request->user()->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager', 'department-head'])->isNotEmpty(), 403);
    }
}
