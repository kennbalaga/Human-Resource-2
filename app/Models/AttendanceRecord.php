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
