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
        'room_id',
        'room_booking_id',
        'cross_unit',
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
            'context' => self::auditContext($model, $action),
        ]);
    }

    /**
     * What a reviewer needs to reconstruct a room move six months later.
     *
     * An 'updated' row that says only "updated" cannot answer the one question
     * anybody asks of this trail — who moved this nurse out of the theatre —
     * so a change of room is recorded as the pair of rooms it moved between.
     *
     * @return array<string, mixed>|null
     */
    private static function auditContext(self $model, string $action): ?array
    {
        if ($action === 'updated' && $model->wasChanged('room_id')) {
            return [
                'room_changed' => true,
                'room_id_from' => $model->getOriginal('room_id'),
                'room_id_to' => $model->room_id,
            ];
        }

        return $model->room_id === null ? null : ['room_id' => $model->room_id];
    }

    protected function casts(): array
    {
        return [
            'work_date' => ScheduleDate::class,
            'cross_unit' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function roomBooking(): BelongsTo
    {
        return $this->belongsTo(RoomBooking::class);
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
