<?php

namespace App\Models;

use App\Casts\ScheduleDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One case on a theatre list: the room held between two clock times.
 */
class RoomBooking extends Model
{
    use HasFactory;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'room_id',
        'shift_id',
        'work_date',
        'start_time',
        'end_time',
        'purpose',
        'lead_employee_id',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => ScheduleDate::class,
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_PLANNED => 'Planned',
            self::STATUS_CONFIRMED => 'Confirmed',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    /**
     * The statuses that still hold the room. A cancelled or completed case does
     * not, which is what lets the slot be booked again.
     *
     * @return array<int, string>
     */
    public static function holdingStatuses(): array
    {
        return [self::STATUS_PLANNED, self::STATUS_CONFIRMED];
    }

    public function scopeHolding(Builder $query): Builder
    {
        return $query->whereIn('status', self::holdingStatuses());
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'lead_employee_id');
    }

    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(ScheduleAssignment::class);
    }

    public function isHoldingTheRoom(): bool
    {
        return in_array($this->status, self::holdingStatuses(), true);
    }

    public function getWindowAttribute(): string
    {
        return substr((string) $this->start_time, 0, 5).' – '.substr((string) $this->end_time, 0, 5);
    }
}
