<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttendanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'office_location_id',
        'attendance_date',
        'check_in_at',
        'check_in_method',
        'check_in_biometric_device_id',
        'check_out_at',
        'check_out_method',
        'check_out_biometric_device_id',
        'status',
        'approval_status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'late_minutes',
        'undertime_minutes',
        'overtime_minutes',
        'worked_minutes',
        'notes',
        'check_in_ip_address',
        'check_out_ip_address',
        'check_in_user_agent',
        'check_out_user_agent',
        'schedule_assignment_id',
        'shift_start_at',
        'shift_end_at',
        'binding_source',
        'schedule_status',
        'early_minutes',
        'override_authorised_by',
        'override_reason',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'approved_at' => 'datetime',
            'shift_start_at' => 'datetime',
            'shift_end_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function officeLocation(): BelongsTo
    {
        return $this->belongsTo(OfficeLocation::class);
    }

    public function checkInBiometricDevice(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'check_in_biometric_device_id');
    }

    public function checkOutBiometricDevice(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'check_out_biometric_device_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function timesheetEntry(): HasOne
    {
        return $this->hasOne(TimesheetEntry::class);
    }

    public function scheduleAssignment(): BelongsTo
    {
        return $this->belongsTo(ScheduleAssignment::class);
    }

    public function overrideAuthoriser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_authorised_by');
    }

    public function getWorkedHoursAttribute(): string
    {
        return sprintf('%dh %02dm', intdiv($this->worked_minutes, 60), $this->worked_minutes % 60);
    }

    public function getCheckInMethodLabelAttribute(): string
    {
        return $this->captureLabel($this->check_in_method);
    }

    public function getCheckOutMethodLabelAttribute(): string
    {
        return $this->captureLabel($this->check_out_method);
    }

    /**
     * How a capture source reads in a table. Title case alone would render the
     * QR badge as "Qr".
     */
    private function captureLabel(?string $method): string
    {
        return match ($method ?? 'manual') {
            'qr' => 'QR badge',
            'biometric' => 'Biometric',
            default => 'Manual',
        };
    }
}
