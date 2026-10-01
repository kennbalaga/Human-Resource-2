<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The one definition of a scheduling week.
 *
 * Every weekly rule (days off, maximum hours, night-shift limits, the weekly
 * compliance review, recurrence intervals) and every calendar grid reads its
 * week boundaries from here, so the rules and the screens can never disagree
 * about which seven days make up "this week". The first day comes from
 * config('schedule.calendar_week_starts_on'), using Carbon's day numbers
 * (0 = Sunday … 6 = Saturday).
 *
 * Weekday values stored on records (recurring-series weekdays, a preferred
 * weekly day off) stay ISO numbers, 1 = Monday … 7 = Sunday. Only the order
 * they are shown in follows the configured first day.
 */
final class ScheduleWeek
{
    /** Carbon day number (0 = Sunday … 6 = Saturday) a scheduling week starts on. */
    public static function startsOn(): int
    {
        return ((int) config('schedule.calendar_week_starts_on', CarbonInterface::SUNDAY)) % 7;
    }

    /** Carbon day number the scheduling week ends on. */
    public static function endsOn(): int
    {
        return (self::startsOn() + 6) % 7;
    }

    public static function start(CarbonInterface $date): Carbon
    {
        return Carbon::instance($date)->copy()->startOfWeek(self::startsOn());
    }

    public static function end(CarbonInterface $date): Carbon
    {
        return Carbon::instance($date)->copy()->endOfWeek(self::endsOn());
    }

    /**
     * ISO weekday numbers (1 = Monday … 7 = Sunday) in display order.
     *
     * @return array<int, int>
     */
    public static function isoWeekdays(): array
    {
        $firstIso = self::startsOn() === 0 ? 7 : self::startsOn();

        return array_map(fn (int $offset): int => (($firstIso - 1 + $offset) % 7) + 1, range(0, 6));
    }

    /**
     * ISO weekday number => full day name, in display order.
     *
     * @return array<int, string>
     */
    public static function isoWeekdayNames(): array
    {
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

        $ordered = [];
        foreach (self::isoWeekdays() as $iso) {
            $ordered[$iso] = $names[$iso];
        }

        return $ordered;
    }

    /**
     * Short day names in display order, for calendar headers.
     *
     * @return array<int, string>
     */
    public static function shortNames(): array
    {
        return array_values(array_map(fn (string $name): string => substr($name, 0, 3), self::isoWeekdayNames()));
    }
}
