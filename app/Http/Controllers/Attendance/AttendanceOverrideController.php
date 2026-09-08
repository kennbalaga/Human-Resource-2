<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceOverrideRequest;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\OfficeLocation;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Where a manager completes a punch that the roster would otherwise refuse
 * (attendance.enforce_published_shift) — an unpublished roster, a last-minute
 * coverage call, or a bank/agency nurse arriving for an unrostered shift.
 * Every use is logged against the manager, with a mandatory reason, on the
 * attendance record itself.
 */
class AttendanceOverrideController extends Controller
{
    use ScopesWorkforceAccess;

    public function index(Request $request): View
    {
        abort_unless(Gate::forUser($request->user())->allows('attendance.override'), 403);

        $office = OfficeLocation::query()->where('is_active', true)->first();
        abort_if($office === null, 503, 'No active attendance location has been configured.');

        $query = trim((string) $request->string('q'));
        $employees = collect();

        if ($query !== '') {
            $employees = Employee::query()
                ->visibleTo($request->user())
                ->notArchived()
                ->where('employment_status', '!=', 'inactive')
                ->where(function ($builder) use ($query) {
                    $builder->where('employee_number', 'like', "%{$query}%")
                        ->orWhere('first_name', 'like', "%{$query}%")
                        ->orWhere('last_name', 'like', "%{$query}%");
                })
                ->orderBy('last_name')
                ->limit(20)
                ->get();
        }

        return view('attendance.override', [
            'office' => $office,
            'query' => $query,
            'employees' => $employees,
        ]);
    }

    public function checkIn(AttendanceOverrideRequest $request, AttendanceService $attendanceService): RedirectResponse
    {
        // employee_id arrives in the request body, so the posted id is checked
        // rather than trusted — the search above only hides names, it does not
        // stop a head typing an id from another ward.
        $employee = Employee::query()->findOrFail($request->integer('employee_id'));
        $this->requireSupervision($request, $employee, 'attendance.override.record');
        $office = OfficeLocation::query()->where('is_active', true)->findOrFail($request->integer('office_location_id'));

        $record = $attendanceService->checkIn(
            $employee,
            $office,
            'Manager override by '.$request->user()->name,
            $request->ip(),
            $request->userAgent(),
            'manual',
            null,
            null,
            $request->user(),
            $request->string('reason')->trim()->value(),
        );

        return redirect()->route('attendance.override.index')->with(
            'success',
            "Checked in {$employee->full_name} at ".$record->check_in_at->timezone($office->timezone)->format('g:i A').'.',
        );
    }

    public function checkOut(AttendanceOverrideRequest $request, AttendanceService $attendanceService): RedirectResponse
    {
        $employee = Employee::query()->findOrFail($request->integer('employee_id'));
        $this->requireSupervision($request, $employee, 'attendance.override.record');
        $office = OfficeLocation::query()->where('is_active', true)->findOrFail($request->integer('office_location_id'));

        $today = now()->timezone($office->timezone)->toDateString();
        $existing = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $today)
            ->first();

        $reason = $request->string('reason')->trim()->value();
        $notes = trim(($existing?->notes ? $existing->notes.' ' : '').'Manager override by '.$request->user()->name.": {$reason}");

        $record = $attendanceService->checkOut(
            $employee,
            $office,
            $notes,
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()->route('attendance.override.index')->with(
            'success',
            "Checked out {$employee->full_name}. Worked time: {$record->worked_hours}.",
        );
    }
}
