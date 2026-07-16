<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceActionRequest;
use App\Models\AttendanceRecord;
use App\Models\OfficeLocation;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(Request $request): View
    {
        $employee = $request->user()->employee;
        abort_if($employee === null, 403, 'Your user account is not linked to an employee profile.');

        $office = OfficeLocation::query()->where('is_active', true)->first();
        abort_if($office === null, 503, 'No active attendance location has been configured.');

        $today = now()->timezone($office->timezone)->toDateString();
        $todayRecord = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $today)
            ->first();

        $recentRecords = AttendanceRecord::query()
            ->with('officeLocation')
            ->where('employee_id', $employee->id)
            ->latest('attendance_date')
            ->limit(7)
            ->get();

        return view('attendance.index', [
            'employee' => $employee,
            'office' => $office,
            'todayRecord' => $todayRecord,
            'recentRecords' => $recentRecords,
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
            'notifications' => collect(),
        ]);
    }

    public function checkIn(AttendanceActionRequest $request, AttendanceService $attendanceService): RedirectResponse
    {
        $office = OfficeLocation::query()->where('is_active', true)->findOrFail($request->integer('office_location_id'));

        $record = $attendanceService->checkIn(
            $request->user()->employee,
            $office,
            $this->notes($request),
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()->route('attendance.index')->with(
            'success',
            'Checked in successfully at '.$record->check_in_at->timezone($office->timezone)->format('g:i A').'.',
        );
    }

    public function checkOut(AttendanceActionRequest $request, AttendanceService $attendanceService): RedirectResponse
    {
        $office = OfficeLocation::query()->where('is_active', true)->findOrFail($request->integer('office_location_id'));

        $record = $attendanceService->checkOut(
            $request->user()->employee,
            $office,
            $this->notes($request),
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()->route('attendance.index')->with(
            'success',
            'Checked out successfully. Worked time: '.$record->worked_hours.'.',
        );
    }

    private function notes(AttendanceActionRequest $request): ?string
    {
        return ((string) $request->string('notes')->trim()) ?: null;
    }
}
