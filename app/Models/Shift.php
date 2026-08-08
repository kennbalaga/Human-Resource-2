<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shift extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'start_time',
        'end_time',
        'break_minutes',
        'color',
        'is_active',
        'is_system',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ScheduleAssignment::class);
    }

    public function recurringSchedules(): HasMany
    {
        return $this->hasMany(RecurringSchedule::class);
    }

    public function getCrossesMidnightAttribute(): bool
    {
        return $this->end_time <= $this->start_time;
    }

    /**
     * Anything crossing midnight, or starting in the evening/small hours,
     * counts toward the night-shift limit and consecutive-nights checks.
     */
    public function getIsNightShiftAttribute(): bool
    {
        $hour = (int) Carbon::parse($this->start_time)->format('G');

        return $this->crosses_midnight || $hour >= 18 || $hour < 6;
    }

    public function getDurationMinutesAttribute(): int
    {
        $start = Carbon::parse($this->start_time);
        $end = Carbon::parse($this->end_time);

        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return max(0, (int) floor($start->diffInMinutes($end)) - $this->break_minutes);
    }

    public function getFormattedTimeAttribute(): string
    {
        return Carbon::parse($this->start_time)->format('g:i A')
            .'–'.Carbon::parse($this->end_time)->format('g:i A');
    }
}
