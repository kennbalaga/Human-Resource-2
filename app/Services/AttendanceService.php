<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\OfficeLocation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function checkIn(
        Employee $employee,
        OfficeLocation $office,
        ?string $notes,
        ?string $ipAddress,
        ?string $userAgent,
    ): AttendanceRecord {
        return DB::transaction(function () use ($employee, $office, $notes, $ipAddress, $userAgent) {
            $now = now();
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

            $scheduledStart = $this->officeDateTime(
                $attendanceDate,
                $office->work_start_time,
                $office->timezone,
            );
            $lateThreshold = $scheduledStart->copy()->addMinutes($office->grace_period_minutes);
            $lateMinutes = $localNow->greaterThan($lateThreshold)
                ? (int) floor($scheduledStart->diffInMinutes($localNow))
                : 0;

            return AttendanceRecord::query()->create([
                'employee_id' => $employee->id,
                'office_location_id' => $office->id,
                'attendance_date' => $attendanceDate,
                'check_in_at' => $now,
                'status' => $lateMinutes > 0 ? 'late' : 'present',
                'late_minutes' => $lateMinutes,
                'notes' => $notes,
                'check_in_ip_address' => $ipAddress,
                'check_in_user_agent' => $userAgent,
            ]);
        });
    }

    public function checkOut(
        Employee $employee,
        OfficeLocation $office,
        ?string $notes,
        ?string $ipAddress,
        ?string $userAgent,
    ): AttendanceRecord {
        return DB::transaction(function () use ($employee, $office, $notes, $ipAddress, $userAgent) {
            $now = now();
            $localNow = $now->copy()->timezone($office->timezone);
            $attendanceDate = $localNow->toDateString();

            $record = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $attendanceDate)
                ->lockForUpdate()
                ->first();

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
            $scheduledEnd = $this->officeDateTime(
                $attendanceDate,
                $recordOffice->work_end_time,
                $recordOffice->timezone,
            );
            $grossMinutes = max(0, (int) floor($checkInLocal->diffInMinutes($localNow)));
            $breakMinutes = $grossMinutes >= 300 ? $recordOffice->break_minutes : 0;
            $workedMinutes = max(0, $grossMinutes - $breakMinutes);
            $undertimeMinutes = $localNow->lessThan($scheduledEnd)
                ? (int) floor($localNow->diffInMinutes($scheduledEnd))
                : 0;
            $overtimeMinutes = $localNow->greaterThan($scheduledEnd)
                ? (int) floor($scheduledEnd->diffInMinutes($localNow))
                : 0;

            $record->update([
                'check_out_at' => $now,
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

    private function officeDateTime(string $date, string $time, string $timezone): Carbon
    {
        return Carbon::parse($date.' '.$time, $timezone);
    }
}
