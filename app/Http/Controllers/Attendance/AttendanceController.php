<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AttendanceActionRequest;
use App\Models\OfficeLocation;
use App\Services\Attendance\MyAttendanceService;
use App\Services\AttendanceCaptureSettings;
use App\Services\AttendanceService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(
        Request $request,
        AttendanceCaptureSettings $captureSettings,
        MyAttendanceService $myAttendance,
        AttendanceService $attendanceService,
    ): View {
        $employee = $request->user()->employee;
        abort_if($employee === null, 403, 'Your user account is not linked to an employee profile.');

        $office = OfficeLocation::query()->where('is_active', true)->first();
        abort_if($office === null, 503, 'No active attendance location has been configured.');

        // Includes last night's open record, so a night shift offers Check out
        // after midnight rather than a second Check in.
        $todayRecord = $attendanceService->recordToClose($employee, $office->timezone, now());

        // Scanning writes somebody else's attendance, so the camera belongs to
        // the people who already carry workforce records, not to whoever happens
        // to open the page. Anyone else asking for the scanner gets their own view.
        $canScanQr = Gate::forUser($request->user())->allows('workforce.view');
        $view = $canScanQr && $request->query('view') === 'scanner' ? 'scanner' : 'mine';

        $captureState = $this->captureState($captureSettings);

        /* Which window the records below cover. Anything unrecognised falls
           back to the rolling week rather than emptying the table. */
        $period = $request->query('period') === MyAttendanceService::PERIOD_MONTH
            ? MyAttendanceService::PERIOD_MONTH
            : MyAttendanceService::PERIOD_WEEK;

        return view('attendance.index', [
            'period' => $period,
            'employee' => $employee,
            'office' => $office,
            'canScanQr' => $canScanQr,
            'view' => $view,
            'todayRecord' => $todayRecord,
            'attendance' => $view === 'mine' ? $myAttendance->forEmployee($employee, $office, $period) : null,
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
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

        return redirect($this->destination($request))->with(
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

        return redirect($this->destination($request))->with(
            'success',
            'Checked out successfully. Worked time: '.$record->worked_hours.'.',
        );
    }

    private function notes(AttendanceActionRequest $request): ?string
    {
        return ((string) $request->string('notes')->trim()) ?: null;
    }

    /**
     * Where the confirmation lands. The staff dashboard clocks in and out from its
     * own attendance card, so it asks to be returned to itself rather than pushing
     * the employee onto a page they did not open. The value is a named destination
     * the request has already validated, never a caller-supplied URL.
     */
    private function destination(AttendanceActionRequest $request): string
    {
        return $request->string('return_to')->value() === 'dashboard'
            ? route('dashboard')
            : route('attendance.index');
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
