<?php

namespace App\Models;

use App\Casts\ScheduleDate;
use App\Services\Scheduling\RosterWriteContext;
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
        'work_date',
        'status',
        'notes',
        'created_by',
        'created_via',
        'source_recommendation_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if (! RosterWriteContext::isOpen()) {
                RosterWriteContext::denyWrite($model->exists ? 'update' : 'create');
            }
        });

        static::deleting(function (self $model): void {
            if (! RosterWriteContext::isOpen()) {
                RosterWriteContext::denyWrite('delete');
            }
        });

        static::created(fn (self $model) => self::recordAudit($model, 'created'));
        static::updated(fn (self $model) => self::recordAudit($model, 'updated'));
        static::deleted(fn (self $model) => self::recordAudit($model, 'deleted'));
    }

    private static function recordAudit(self $model, string $action): void
    {
        ScheduleAssignmentAudit::query()->create([
            'schedule_assignment_id' => $model->id,
            'employee_id' => $model->employee_id,
            'work_date' => $model->work_date,
            'action' => $action,
            'actor_id' => RosterWriteContext::currentActor()?->id,
            'created_via' => $model->created_via,
            'unattended' => RosterWriteContext::isUnattended(),
            // The only thing this column ever carried was the pair of rooms a
            // move went between, and rooms are no longer part of the roster.
            'context' => null,
        ]);
    }

    protected function casts(): array
    {
        return [
            'work_date' => ScheduleDate::class,
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

    public function sourceRecommendation(): BelongsTo
    {
        return $this->belongsTo(ScheduleRecommendation::class, 'source_recommendation_id');
    }
}
