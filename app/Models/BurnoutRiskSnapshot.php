<?php

namespace App\Models;

use App\Casts\ScheduleDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day's burnout risk assessment for one employee.
 *
 * Written only by BurnoutRiskService, through an upsert that stores
 * `as_of_date` as a bare date. The ScheduleDate cast keeps it that way on any
 * other write, so an equality lookup on the date cannot miss a row stored with
 * a midnight time attached.
 */
class BurnoutRiskSnapshot extends Model
{
    public const LEVEL_LOW = 'low';

    public const LEVEL_MODERATE = 'moderate';

    public const LEVEL_HIGH = 'high';

    public const LEVELS = [self::LEVEL_LOW, self::LEVEL_MODERATE, self::LEVEL_HIGH];

    protected $fillable = [
        'employee_id',
        'as_of_date',
        'score',
        'previous_score',
        'level',
        'factors',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => ScheduleDate::class,
            'score' => 'float',
            'previous_score' => 'float',
            'factors' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
