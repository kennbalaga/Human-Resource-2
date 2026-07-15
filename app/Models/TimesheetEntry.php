<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimesheetEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'timesheet_id',
        'attendance_record_id',
        'work_date',
        'regular_minutes',
        'overtime_minutes',
        'late_minutes',
        'undertime_minutes',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return ['work_date' => 'date'];
    }

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }
}
