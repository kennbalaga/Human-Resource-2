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
    public function __construct(private readonly GeoFenceService $geoFenceService) {}

    /**
     * @param  array{latitude: float, longitude: float, accuracy?: float|null, notes?: string|null}  $locationData
     */
    public function checkIn(
        Employee $employee,
        OfficeLocation $office,
        array $locationData,
        ?string $ipAddress,
        ?string $userAgent,
    ): AttendanceRecord {
        return DB::transaction(function () use ($employee, $office, $locationData, $ipAddress, $userAgent) {
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

            $geoFence = $this->geoFenceService->evaluate(
                $office,
                $locationData['latitude'],
                $locationData['longitude'],
            );
            $this->geoFenceService->ensureAllowed($office, $geoFence['within_geofence']);

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
                'check_in_latitude' => $locationData['latitude'],
                'check_in_longitude' => $locationData['longitude'],
                'check_in_accuracy_meters' => $locationData['accuracy'] ?? null,
                'check_in_distance_meters' => $geoFence['distance_meters'],
                'check_in_within_geofence' => $geoFence['within_geofence'],
                'status' => $lateMinutes > 0 ? 'late' : 'present',
                'late_minutes' => $lateMinutes,
                'notes' => $locationData['notes'] ?? null,
                'check_in_ip_address' => $ipAddress,
                'check_in_user_agent' => $userAgent,
            ]);
        });
    }

    /**
     * @param  array{latitude: float, longitude: float, accuracy?: float|null, notes?: string|null}  $locationData
     */
    public function checkOut(
        Employee $employee,
        OfficeLocation $office,
        array $locationData,
        ?string $ipAddress,
        ?string $userAgent,
    ): AttendanceRecord {
        return DB::transaction(function () use ($employee, $office, $locationData, $ipAddress, $userAgent) {
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
            $geoFence = $this->geoFenceService->evaluate(
                $recordOffice,
                $locationData['latitude'],
                $locationData['longitude'],
            );
            $this->geoFenceService->ensureAllowed($recordOffice, $geoFence['within_geofence']);

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
                'check_out_latitude' => $locationData['latitude'],
                'check_out_longitude' => $locationData['longitude'],
                'check_out_accuracy_meters' => $locationData['accuracy'] ?? null,
                'check_out_distance_meters' => $geoFence['distance_meters'],
                'check_out_within_geofence' => $geoFence['within_geofence'],
                'worked_minutes' => $workedMinutes,
                'undertime_minutes' => $undertimeMinutes,
                'overtime_minutes' => $overtimeMinutes,
                'notes' => $locationData['notes'] ?? $record->notes,
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
