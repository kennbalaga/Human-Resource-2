<?php

namespace Tests\Feature\Schedule;

use App\Services\ScheduleService;
use App\Support\ScheduleWeek;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * A scheduling week runs Sunday to Saturday, and every weekly rule and grid
 * reads its boundaries from one place.
 */
class ScheduleWeekTest extends TestCase
{
    public function test_a_scheduling_week_runs_sunday_to_saturday(): void
    {
        $wednesday = Carbon::parse('2026-09-30', config('schedule.timezone'));

        $this->assertSame('2026-09-27', ScheduleWeek::start($wednesday)->toDateString());
        $this->assertSame('2026-10-03', ScheduleWeek::end($wednesday)->toDateString());
        $this->assertSame('2026-09-27', ScheduleWeek::start(Carbon::parse('2026-09-27'))->toDateString(), 'A Sunday opens its own week.');
        $this->assertSame('2026-10-03', ScheduleWeek::end(Carbon::parse('2026-10-03'))->toDateString(), 'A Saturday closes its own week.');
    }

    public function test_weekday_lists_keep_iso_values_in_sunday_first_order(): void
    {
        $this->assertSame([7, 1, 2, 3, 4, 5, 6], ScheduleWeek::isoWeekdays());
        $this->assertSame(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], ScheduleWeek::shortNames());
        $this->assertSame(7, array_key_first(ScheduleWeek::isoWeekdayNames()));
    }

    public function test_the_week_start_can_still_be_configured(): void
    {
        config(['schedule.calendar_week_starts_on' => Carbon::MONDAY]);

        $this->assertSame('2026-09-28', ScheduleWeek::start(Carbon::parse('2026-09-30'))->toDateString());
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], ScheduleWeek::isoWeekdays());
    }

    public function test_every_other_week_counts_sunday_to_saturday_weeks(): void
    {
        // Starts on Wednesday Sep 30; Sundays, every second week. Sunday Oct 4
        // opens the series' second week, so the first Sunday worked is Oct 11.
        // (With Monday weeks it would have been Oct 4 and Oct 18.)
        $dates = app(ScheduleService::class)->recurrenceDates('2026-09-30', '2026-10-31', 'weekly', [7], 2);

        $this->assertSame(['2026-10-11', '2026-10-25'], $dates->map->toDateString()->all());
    }
}
