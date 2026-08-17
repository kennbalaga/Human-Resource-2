<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Services\Attendance\AttendanceQrService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request, AttendanceQrService $attendanceCodes): View
    {
        $user = $request->user()->load(['roles', 'employee.department', 'employee.position', 'employee.supervisor.user']);
        $employee = $user->employee;
        $employee?->loadCount(['attendanceRecords', 'scheduleAssignments', 'timesheets', 'leaveRequests']);

        return view('profile.show', [
            'user' => $user,
            'employee' => $employee,
            'attendanceQrPayload' => $employee ? $attendanceCodes->payloadFor($employee) : null,
            'currentRole' => $user->roles->pluck('name')->join(', ') ?: 'Employee',
            'notifications' => collect(),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $request->user()->employee->update($request->validated());

        return back()->with('success', 'Your contact information has been updated.');
    }

    /**
     * Retire the badge this employee has been carrying and issue a new one, for
     * when the old code has been lost, shared, or photographed by someone else.
     */
    public function regenerateAttendanceQr(Request $request, AttendanceQrService $attendanceCodes): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_if($employee === null, 403, 'Your user account is not linked to an employee profile.');

        $attendanceCodes->regenerate($employee);

        return back()->with('success', 'A new attendance QR code has been issued. Download it again — your previous code no longer works.');
    }
}
