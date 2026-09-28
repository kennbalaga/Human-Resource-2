<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\OfficeLocation;
use App\Services\Attendance\AttendanceQrService;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The entrance scanner. A scanning officer points the camera at an employee's
 * badge; the code decides whose attendance is written, and whether this scan is
 * the arrival or the departure.
 *
 * Only HR, system administrators and department heads may reach this — a scan
 * clocks somebody else in, so it is a supervisory act, not self-service.
 */
class AttendanceQrScanController extends Controller
{
    /**
     * A camera reports the same code many times a second, and a queue at the
     * door produces its own double-taps — a badge presented again because the
     * holder did not see the confirmation. Anything inside this window replays
     * the previous outcome instead of counting as a fresh scan.
     *
     * Kept short on purpose: long enough to absorb a badge held in front of the
     * lens or an operator re-scanning because they missed the confirmation,
     * short enough that a deliberate correction — the wrong badge was scanned,
     * or checkout really was seconds later — does not sit locked out feeling
     * broken. The response also carries when the window ends, so the scanner
     * can say so instead of just repeating "already recorded".
     */
    private const REPEAT_SCAN_WINDOW_SECONDS = 20;

    public function store(
        Request $request,
        AttendanceQrService $codes,
        AttendanceService $attendance,
    ): JsonResponse {
        abort_unless(Gate::forUser($request->user())->allows('workforce.view'), 403);

        $validated = $request->validate([
            'payload' => ['required', 'string', 'max:255'],
        ]);

        $office = OfficeLocation::query()->where('is_active', true)->first();
        if ($office === null) {
            throw ValidationException::withMessages([
                'attendance' => 'No active attendance location has been configured.',
            ]);
        }

        $employee = $codes->resolve($validated['payload']);
        // Today's record, or last night's still-open one: a scan at 06:00 ends
        // a night shift rather than starting a new day.
        $existing = $attendance->recordToClose($employee, $office->timezone, now());

        if ($replay = $this->replayOfRecentScan($existing, $employee, $office->timezone)) {
            return response()->json($replay);
        }

        // Said plainly and with the name on it: the operator is looking at the
        // employee, not at their own record, so "you have already checked out"
        // would be addressed to the wrong person.
        if ($existing?->check_out_at !== null) {
            throw ValidationException::withMessages([
                'qr_payload' => "{$employee->full_name} has already completed attendance for today.",
            ]);
        }

        $action = $existing?->check_in_at === null ? 'check-in' : 'check-out';
        $scannedBy = 'QR badge scanned by '.$request->user()->name.'.';

        $record = $action === 'check-in'
            ? $attendance->checkIn($employee, $office, $scannedBy, $request->ip(), $request->userAgent(), 'qr')
            : $attendance->checkOut(
                $employee,
                $office,
                trim(($existing?->notes ? $existing->notes.' ' : '').$scannedBy),
                $request->ip(),
                $request->userAgent(),
                'qr',
            );

        return response()->json(
            $this->result($employee, $record, $action, $office->timezone) + ['repeat' => false],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function replayOfRecentScan(?AttendanceRecord $record, Employee $employee, string $timezone): ?array
    {
        if ($record === null) {
            return null;
        }

        $last = $record->check_out_at ?? $record->check_in_at;
        if ($last === null || $last->diffInSeconds(now()) > self::REPEAT_SCAN_WINDOW_SECONDS) {
            return null;
        }

        return $this->result(
            $employee,
            $record,
            $record->check_out_at !== null ? 'check-out' : 'check-in',
            $timezone,
        ) + [
            'repeat' => true,
            'retry_at' => $last->copy()->addSeconds(self::REPEAT_SCAN_WINDOW_SECONDS)->timezone($timezone)->format('g:i:s A'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Employee $employee, AttendanceRecord $record, string $action, string $timezone): array
    {
        $at = ($action === 'check-in' ? $record->check_in_at : $record->check_out_at)?->timezone($timezone);

        return [
            'action' => $action,
            'employee' => [
                'name' => $employee->full_name,
                'employee_number' => $employee->employee_number,
                'department' => $employee->department?->name,
                'initials' => $this->initials($employee),
            ],
            'recorded_at' => $at?->format('g:i:s A'),
            'status' => $record->status,
            'late_minutes' => $record->late_minutes,
            'worked_hours' => $action === 'check-out' ? $record->worked_hours : null,
        ];
    }

    private function initials(Employee $employee): string
    {
        return strtoupper(mb_substr($employee->first_name, 0, 1).mb_substr($employee->last_name, 0, 1));
    }
}
