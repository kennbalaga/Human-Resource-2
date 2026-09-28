<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\Employee;
use App\Models\OfficeLocation;
use App\Models\User;
use App\Services\Scheduling\AttendanceScheduleSettings;
use App\Services\Scheduling\ShiftResolution;
use App\Services\Scheduling\ShiftResolver;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function __construct(
        private readonly ShiftResolver $shiftResolver,
        private readonly AttendanceScheduleSettings $scheduleSettings,
    ) {}

    public function checkIn(
        Employee $employee,
        OfficeLocation $office,
        ?string $notes,
        ?string $ipAddress,
        ?string $userAgent,
        string $method = 'manual',
        ?BiometricDevice $biometricDevice = null,
        ?CarbonInterface $occurredAt = null,
        ?User $authorizingManager = null,
        ?string $overrideReason = null,
    ): AttendanceRecord {
        $this->validateCaptureSource($method, $biometricDevice);

        return DB::transaction(function () use ($employee, $office, $notes, $ipAddress, $userAgent, $method, $biometricDevice, $occurredAt, $authorizingManager, $overrideReason) {
            $now = $occurredAt ? Carbon::instance($occurredAt)->copy() : now();
            $localNow = $now->copy()->timezone($office->timezone);
            $attendanceDate = $localNow->toDateString();

            $existing = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $attendanceDate)
                ->lockForUpdate()
                ->first();

            if ($existing?->check_in_at !== null) {
                throw ValidationException::withMessages([
                    'attendance' => 'You have already checked in today.',
                ]);
            }

            $resolution = $this->shiftResolver->forPunch(
                $employee,
                $now,
                $this->scheduleSettings->earlyWindowMinutes(),
                $this->scheduleSettings->graceMinutes(),
                $this->scheduleSettings->lateBindMinutes(),
            );

            $scheduleColumns = $this->scheduleColumnsFor($resolution, $localNow);

            $overrideColumns = ['override_authorised_by' => null, 'override_reason' => null];

            if (! $resolution->isBound()
                && $this->scheduleSettings->scheduleAware()
                && $this->scheduleSettings->enforcePublishedShift()
            ) {
                if ($authorizingManager === null) {
                    throw ValidationException::withMessages([
                        'schedule' => 'No published shift covers this punch. Ask a manager or HR to authorise it.',
                    ]);
                }

                $this->authoriseOverride($authorizingManager, $employee, $overrideReason);
                $overrideColumns = [
                    'override_authorised_by' => $authorizingManager->id,
                    'override_reason' => $overrideReason,
                ];
            }

            [$status, $lateMinutes] = $this->checkInTiming($office, $attendanceDate, $localNow, $resolution);

            return AttendanceRecord::query()->create([
                'employee_id' => $employee->id,
                'office_location_id' => $office->id,
                'attendance_date' => $attendanceDate,
                'check_in_at' => $now,
                'check_in_method' => $method,
                'check_in_biometric_device_id' => $biometricDevice?->id,
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'notes' => $notes,
                'check_in_ip_address' => $ipAddress,
                'check_in_user_agent' => $userAgent,
                ...$scheduleColumns,
                ...$overrideColumns,
            ]);
        });
    }

    public function checkOut(
        Employee $employee,
        OfficeLocation $office,
        ?string $notes,
        ?string $ipAddress,
        ?string $userAgent,
        string $method = 'manual',
        ?BiometricDevice $biometricDevice = null,
        ?CarbonInterface $occurredAt = null,
    ): AttendanceRecord {
        $this->validateCaptureSource($method, $biometricDevice);

        return DB::transaction(function () use ($employee, $office, $notes, $ipAddress, $userAgent, $method, $biometricDevice, $occurredAt) {
            $now = $occurredAt ? Carbon::instance($occurredAt)->copy() : now();
            $localNow = $now->copy()->timezone($office->timezone);

            $record = $this->recordToClose($employee, $office->timezone, $now, lock: true);

            if ($record === null || $record->check_in_at === null) {
                throw ValidationException::withMessages([
                    'attendance' => 'Check in before attempting to check out.',
                ]);
            }

            if ($record->check_out_at !== null) {
                throw ValidationException::withMessages([
                    'attendance' => 'You have already checked out today.',
                ]);
            }

            $recordOffice = $record->officeLocation ?? $office;
            $checkInLocal = $record->check_in_at->copy()->timezone($recordOffice->timezone);
            if ($localNow->lessThan($checkInLocal)) {
                throw ValidationException::withMessages([
                    'attendance' => 'Check-out time cannot be earlier than check-in time.',
                ]);
            }

            $grossMinutes = max(0, (int) floor($checkInLocal->diffInMinutes($localNow)));
            $breakMinutes = $grossMinutes >= 300 ? $recordOffice->break_minutes : 0;
            $workedMinutes = max(0, $grossMinutes - $breakMinutes);

            // The record's own date, not today's: a night shift checked out of
            // after midnight is still timed against the day it started on.
            [$undertimeMinutes, $overtimeMinutes] = $this->checkOutTiming(
                $recordOffice,
                $record->attendance_date->toDateString(),
                $localNow,
                $record,
                $workedMinutes,
            );

            $record->update([
                'check_out_at' => $now,
                'check_out_method' => $method,
                'check_out_biometric_device_id' => $biometricDevice?->id,
                'worked_minutes' => $workedMinutes,
                'undertime_minutes' => $undertimeMinutes,
                'overtime_minutes' => $overtimeMinutes,
                'notes' => $notes ?? $record->notes,
                'check_out_ip_address' => $ipAddress,
                'check_out_user_agent' => $userAgent,
            ]);

            return $record->refresh();
        });
    }

    /**
     * The record a check-out at this moment would close.
     *
     * Normally that is today's. A night shift is the exception: 22:00–06:00 is
     * checked into on one date and out of on the next, so after midnight the
     * record to close is yesterday's -- but only while it is still open and the
     * shift it was bound to ends today. A day shift somebody forgot to close
     * yesterday is not picked up here; that is a missing time-out, not a
     * check-out.
     */
    public function recordToClose(Employee $employee, string $timezone, CarbonInterface $at, bool $lock = false): ?AttendanceRecord
    {
        $localNow = Carbon::instance($at)->copy()->timezone($timezone);
        $query = fn () => AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->when($lock, fn ($builder) => $builder->lockForUpdate());

        $today = $query()->whereDate('attendance_date', $localNow->toDateString())->first();

        if ($today !== null) {
            return $today;
        }

        $overnight = $query()
            ->whereDate('attendance_date', $localNow->copy()->subDay()->toDateString())
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->whereNotNull('shift_end_at')
            ->first();

        return $overnight !== null && $overnight->shift_end_at->copy()->timezone($timezone)->isSameDay($localNow)
            ? $overnight
            : null;
    }

    /**
     * @return array{schedule_assignment_id: int|null, shift_start_at: Carbon|null, shift_end_at: Carbon|null, binding_source: string, schedule_status: string, early_minutes: int}
     */
    private function scheduleColumnsFor(ShiftResolution $resolution, Carbon $localNow): array
    {
        if (! $resolution->isBound()) {
            return [
                'schedule_assignment_id' => null,
                'shift_start_at' => null,
                'shift_end_at' => null,
                'binding_source' => 'override',
                'schedule_status' => ShiftResolution::UNSCHEDULED,
                'early_minutes' => 0,
            ];
        }

        $start = $resolution->start();
        $earlyMinutes = $localNow->lessThan($start) ? (int) floor($localNow->diffInMinutes($start)) : 0;

        return [
            'schedule_assignment_id' => $resolution->assignment->id,
            // Eloquent's datetime cast formats a Carbon instance in whatever
            // timezone it already carries rather than normalising it, so a
            // Manila-timezone instant from ScheduleService::intervalFor() must
            // be converted to the app's UTC storage timezone explicitly —
            // otherwise "17:00 Asia/Manila" is written as if it were "17:00 UTC".
            'shift_start_at' => $start->copy()->utc(),
            'shift_end_at' => $resolution->end()->copy()->utc(),
            'binding_source' => 'scheduled',
            'schedule_status' => $resolution->scheduleStatus,
            'early_minutes' => $earlyMinutes,
        ];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function checkInTiming(OfficeLocation $office, string $attendanceDate, Carbon $localNow, ShiftResolution $resolution): array
    {
        if ($this->scheduleSettings->scheduleAware() && $resolution->isBound()) {
            if ($resolution->scheduleStatus === ShiftResolution::LATE) {
                $threshold = $resolution->start()->copy()->addMinutes($this->scheduleSettings->graceMinutes());

                return ['late', (int) floor($threshold->diffInMinutes($localNow))];
            }

            return ['present', 0];
        }

        $scheduledStart = $this->officeDateTime($attendanceDate, $office->work_start_time, $office->timezone);
        $lateThreshold = $scheduledStart->copy()->addMinutes($office->grace_period_minutes);
        $lateMinutes = $localNow->greaterThan($lateThreshold)
            ? (int) floor($scheduledStart->diffInMinutes($localNow))
            : 0;

        return [$lateMinutes > 0 ? 'late' : 'present', $lateMinutes];
    }

    /**
     * @return array{0: int, 1: int} [undertimeMinutes, overtimeMinutes]
     */
    private function checkOutTiming(
        OfficeLocation $recordOffice,
        string $attendanceDate,
        Carbon $localNow,
        AttendanceRecord $record,
        int $workedMinutes,
    ): array {
        if ($this->scheduleSettings->scheduleAware() && $record->shift_end_at !== null) {
            $shiftEnd = $record->shift_end_at->copy()->timezone($recordOffice->timezone);
            $overtimeMinutes = $localNow->greaterThan($shiftEnd)
                ? (int) floor($shiftEnd->diffInMinutes($localNow))
                : 0;

            $rosteredDuration = $record->scheduleAssignment?->shift?->duration_minutes ?? 0;
            $undertimeMinutes = max(0, $rosteredDuration - $workedMinutes);

            return [$undertimeMinutes, $overtimeMinutes];
        }

        $scheduledEnd = $this->officeDateTime($attendanceDate, $recordOffice->work_end_time, $recordOffice->timezone);
        $undertimeMinutes = $localNow->lessThan($scheduledEnd)
            ? (int) floor($localNow->diffInMinutes($scheduledEnd))
            : 0;
        $overtimeMinutes = $localNow->greaterThan($scheduledEnd)
            ? (int) floor($scheduledEnd->diffInMinutes($localNow))
            : 0;

        return [$undertimeMinutes, $overtimeMinutes];
    }

    private function authoriseOverride(User $manager, Employee $employee, ?string $overrideReason): void
    {
        if (! Gate::forUser($manager)->allows('attendance.override')) {
            throw ValidationException::withMessages([
                'schedule' => 'You are not authorised to override an unscheduled punch.',
            ]);
        }

        if ($manager->employee?->id === $employee->id) {
            throw ValidationException::withMessages([
                'schedule' => 'You cannot authorise your own attendance.',
            ]);
        }

        if ($overrideReason === null || trim($overrideReason) === '') {
            throw ValidationException::withMessages([
                'schedule' => 'A reason is required to authorise an unscheduled punch.',
            ]);
        }
    }

    private function officeDateTime(string $date, string $time, string $timezone): Carbon
    {
        return Carbon::parse($date.' '.$time, $timezone);
    }

    private function validateCaptureSource(string $method, ?BiometricDevice $biometricDevice): void
    {
        // 'qr' is the badge scanner standing in for the fingerprint terminal
        // until it is installed. It is captured at the entrance by a scanning
        // officer rather than by the employee, so like a biometric scan it
        // records no device of its own and is never self-served.
        if (! in_array($method, ['manual', 'biometric', 'qr'], true)) {
            throw new \InvalidArgumentException('Unsupported attendance capture method.');
        }

        if ($method === 'biometric' && $biometricDevice === null) {
            throw new \InvalidArgumentException('Biometric attendance requires a source device.');
        }

        if ($method !== 'biometric' && $biometricDevice !== null) {
            throw new \InvalidArgumentException(ucfirst($method).' attendance cannot reference a biometric device.');
        }
    }
}
