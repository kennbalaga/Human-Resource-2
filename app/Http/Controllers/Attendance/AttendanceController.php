<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceActionRequest;
use App\Models\AttendanceRecord;
use App\Models\OfficeLocation;
use App\Services\AttendanceCaptureSettings;
use App\Services\AttendanceService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(Request $request, AttendanceCaptureSettings $captureSettings): View
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
            ->with(['officeLocation', 'checkInBiometricDevice', 'checkOutBiometricDevice'])
            ->where('employee_id', $employee->id)
            ->latest('attendance_date')
            ->limit(7)
            ->get();

        $captureState = $this->captureState($captureSettings);

        return view('attendance.index', [
            'employee' => $employee,
            'office' => $office,
            'todayRecord' => $todayRecord,
            'recentRecords' => $recentRecords,
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
            'notifications' => collect(),
            'attendanceCaptureMode' => $captureState['mode'],
            'attendanceCaptureState' => $captureState['identifier'],
            'manualAttendanceAllowed' => $captureState['manual_allowed'],
            'manualAttendanceReasonRequired' => $captureState['reason_required'],
            'manualAttendanceExpiresAt' => $captureState['expires_at'],
        ]);
    }

    public function state(AttendanceCaptureSettings $captureSettings): JsonResponse
    {
        $captureState = $this->captureState($captureSettings);

        return response()->json([
            'state' => $captureState['identifier'],
            'mode' => $captureState['mode'],
            'expires_at' => $captureState['expires_at']?->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    public function checkIn(
        AttendanceActionRequest $request,
        AttendanceService $attendanceService,
        AttendanceCaptureSettings $captureSettings,
    ): RedirectResponse {
        $this->ensureManualAttendanceAllowed($captureSettings, $request);
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

    public function checkOut(
        AttendanceActionRequest $request,
        AttendanceService $attendanceService,
        AttendanceCaptureSettings $captureSettings,
    ): RedirectResponse {
        $this->ensureManualAttendanceAllowed($captureSettings, $request);
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

    private function ensureManualAttendanceAllowed(
        AttendanceCaptureSettings $captureSettings,
        AttendanceActionRequest $request,
    ): void {
        if (! $captureSettings->manualAllowed()) {
            throw ValidationException::withMessages([
                'attendance' => 'Manual website attendance is disabled. Use the biometric terminal or contact HR.',
            ]);
        }

        if ($captureSettings->manualReasonRequired() && $this->notes($request) === null) {
            throw ValidationException::withMessages([
                'notes' => 'A reason is required while Emergency Manual mode is active.',
            ]);
        }
    }

    /**
     * @return array{mode: string, identifier: string, manual_allowed: bool, reason_required: bool, expires_at: CarbonInterface|null}
     */
    private function captureState(AttendanceCaptureSettings $captureSettings): array
    {
        $mode = $captureSettings->mode();
        $expiresAt = $mode === AttendanceCaptureSettings::EMERGENCY_MANUAL
            ? $captureSettings->expiresAt()
            : null;

        return [
            'mode' => $mode,
            'identifier' => $mode.':'.($expiresAt?->getTimestamp() ?? ''),
            'manual_allowed' => in_array($mode, [
                AttendanceCaptureSettings::HYBRID,
                AttendanceCaptureSettings::EMERGENCY_MANUAL,
            ], true),
            'reason_required' => $mode === AttendanceCaptureSettings::EMERGENCY_MANUAL,
            'expires_at' => $expiresAt,
        ];
    }
}
