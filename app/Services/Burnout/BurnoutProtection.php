<?php

namespace App\Services\Burnout;

use App\Models\BurnoutRiskSnapshot;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The stricter scheduling limits for employees at high burnout risk.
 *
 * One definition, read by every place that proposes or checks a roster: bulk
 * fill, the rotation assistant, the roster board and single-shift
 * recommendations. The generators keep protected employees inside these
 * limits on their own. The roster board lets a reviewer go past them by hand,
 * but flags every such placement and asks for a justification before it can be
 * published -- the same Tier B treatment as a consecutive-night streak.
 *
 * The limits come on top of the ordinary ones, never instead of them: a
 * protected employee is still held to the legal rest rules first.
 */
class BurnoutProtection
{
    public const REASON_PREFIX = 'High burnout risk';

    public function __construct(private readonly BurnoutRiskService $risk) {}

    public function enabled(): bool
    {
        return (bool) config('burnout.protection.enabled');
    }

    /**
     * Today's assessment for each of these employees, protected or not.
     *
     * @param  array<int, int|string>  $employeeIds
     * @return Collection<int, BurnoutRiskSnapshot> keyed by employee id
     */
    public function assessments(array $employeeIds): Collection
    {
        return $this->risk->current($employeeIds);
    }

    /**
     * The protected employees among these assessments.
     *
     * @param  Collection<int, BurnoutRiskSnapshot>  $assessments
     * @return Collection<int, BurnoutRiskSnapshot> keyed by employee id
     */
    public function protectedAmong(Collection $assessments): Collection
    {
        return $assessments->filter(fn (BurnoutRiskSnapshot $snapshot) => $this->risk->isProtectedLevel($snapshot->level));
    }

    public function daysOffPerWeek(): int
    {
        return max(0, (int) config('burnout.protection.days_off_per_week'));
    }

    /**
     * Why working this shift on this date would take a protected employee past
     * their limits, or null when it would not.
     *
     * @param  Collection<int, ScheduleAssignment>  $assignments  The employee's other assignments, shift loaded, planned ones included
     */
    public function blockReason(Shift $shift, Carbon $date, Collection $assignments): ?string
    {
        $limits = config('burnout.protection');
        $weekStart = $date->copy()->startOfWeek();
        $weekEnd = $date->copy()->endOfWeek();
        $week = $assignments->filter(fn (ScheduleAssignment $assignment) => $assignment->shift !== null
            && $assignment->work_date->betweenIncluded($weekStart, $weekEnd));

        $daysOff = $this->daysOffPerWeek();
        $workedDays = $week->map(fn (ScheduleAssignment $assignment) => $assignment->work_date->toDateString())->unique();
        if ($daysOff > 0
            && ! $workedDays->contains($date->toDateString())
            && $workedDays->count() >= 7 - $daysOff) {
            return self::REASON_PREFIX." — {$daysOff} rest days kept free this week";
        }

        $maxHours = (int) $limits['max_weekly_hours'];
        $minutes = $week->sum(fn (ScheduleAssignment $assignment) => $assignment->shift->duration_minutes);
        if ($maxHours > 0 && $minutes + $shift->duration_minutes > $maxHours * 60) {
            return self::REASON_PREFIX." — weekly hours capped at {$maxHours}";
        }

        $maxNights = (int) $limits['max_night_shifts_per_week'];
        if ($shift->is_night_shift
            && $week->filter(fn (ScheduleAssignment $assignment) => $assignment->shift->is_night_shift)->count() >= $maxNights) {
            return self::REASON_PREFIX." — night shifts capped at {$maxNights} a week";
        }

        return null;
    }
}
