<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'department_id',
        'position_id',
        'supervisor_id',
        'employee_number',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'employment_status',
        'hire_date',
        'contact_number',
        'address',
        'preferred_shift_id',
        'preferred_weekly_off_day',
    ];

    /**
     * Anyone holding this can mint that employee's attendance badge, so it must
     * never ride along in an API payload or a debug dump.
     *
     * @var list<string>
     */
    protected $hidden = [
        'attendance_qr_secret',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'preferred_weekly_off_day' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_id');
    }

    public function preferredShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'preferred_shift_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'supervisor_id');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function biometricEnrollments(): HasMany
    {
        return $this->hasMany(BiometricEnrollment::class);
    }

    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(ScheduleAssignment::class);
    }

    public function recurringSchedules(): HasMany
    {
        return $this->hasMany(RecurringSchedule::class);
    }

    public function scheduleDayOffs(): HasMany
    {
        return $this->hasMany(ScheduleDayOff::class);
    }

    public function preferredDayOffs(): HasMany
    {
        return $this->hasMany(PreferredDayOff::class);
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function getFullNameAttribute(): string
    {
        return collect([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])->filter()->implode(' ');
    }
}
