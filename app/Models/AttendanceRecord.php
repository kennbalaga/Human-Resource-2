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
        'check_out_at',
        'check_in_latitude',
        'check_in_longitude',
        'check_out_latitude',
        'check_out_longitude',
        'check_in_accuracy_meters',
        'check_out_accuracy_meters',
        'check_in_distance_meters',
        'check_out_distance_meters',
        'check_in_within_geofence',
        'check_out_within_geofence',
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
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'approved_at' => 'datetime',
            'check_in_latitude' => 'decimal:7',
            'check_in_longitude' => 'decimal:7',
            'check_out_latitude' => 'decimal:7',
            'check_out_longitude' => 'decimal:7',
            'check_in_accuracy_meters' => 'decimal:2',
            'check_out_accuracy_meters' => 'decimal:2',
            'check_in_distance_meters' => 'decimal:2',
            'check_out_distance_meters' => 'decimal:2',
            'check_in_within_geofence' => 'boolean',
            'check_out_within_geofence' => 'boolean',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function timesheetEntry(): HasOne
    {
        return $this->hasOne(TimesheetEntry::class);
    }

    public function getWorkedHoursAttribute(): string
    {
        return sprintf('%dh %02dm', intdiv($this->worked_minutes, 60), $this->worked_minutes % 60);
    }
}
