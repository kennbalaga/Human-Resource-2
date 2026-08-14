<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'shift_id',
        'recurring_schedule_id',
        'schedule_recommendation_id',
        'work_date',
        'status',
        'source',
        'notes',
        'created_by',
        'applied_by',
        'was_modified',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'was_modified' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function recurringSchedule(): BelongsTo
    {
        return $this->belongsTo(RecurringSchedule::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(ScheduleRecommendation::class, 'schedule_recommendation_id');
    }
}
