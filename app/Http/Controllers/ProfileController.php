<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Services\Attendance\AttendanceQrService;
use App\Support\Qr\QrEncoder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            // Drawn here rather than in the browser: the badge is the one thing
            // an employee may genuinely need on a machine where the front-end
            // assets are missing or blocked, and a code that fails to appear is
            // indistinguishable to them from a code that does not exist.
            'attendanceQrSvg' => $employee ? QrEncoder::svg($attendanceCodes->payloadFor($employee)) : null,
            'currentRole' => $user->roles->pluck('name')->join(', ') ?: 'Employee',
        ]);
    }

    /**
     * The badge as a file, for printing or for carrying on a phone.
     */
    public function downloadAttendanceQr(Request $request, AttendanceQrService $attendanceCodes): Response
    {
        $employee = $request->user()->employee;
        abort_if($employee === null, 403, 'Your user account is not linked to an employee profile.');

        $payload = $attendanceCodes->payloadFor($employee);
        $name = 'attendance-qr-'.$employee->employee_number;

        // PNG is what a phone gallery and a word processor both understand, but
        // it needs an image extension; the SVG says the same thing and prints
        // more sharply, so it stands in wherever GD is not compiled.
        return extension_loaded('gd')
            ? response(QrEncoder::png($payload), 200, [
                'Content-Type' => 'image/png',
                'Content-Disposition' => 'attachment; filename="'.$name.'.png"',
            ])
            : response(QrEncoder::svg($payload), 200, [
                'Content-Type' => 'image/svg+xml',
                'Content-Disposition' => 'attachment; filename="'.$name.'.svg"',
            ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $request->user()->employee->update($request->validated());

        return back()->with('success', 'Your contact information has been updated.');
    }
}
