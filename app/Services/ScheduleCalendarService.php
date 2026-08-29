<?php

namespace App\Services;

use App\Models\ScheduleAssignment;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The week strip above the dashboard's schedule rail: seven days, today marked,
 * and a head count against each one so the reader can see where the roster is
 * thin before opening it.
 *
 * It answers "which days this week have people on them", nothing more. The
 * detail of who is on which shift belongs to the schedules module, which every
 * day in the strip links out to.
 */
class ScheduleCalendarService
{
    /**
     * Employment statuses that belong to the working roster — the same scope the
     * attendance and shift services count, so the three panels on one dashboard
     * never disagree about the size of the workforce.
     */
    private const WORKFORCE_STATUSES = ['active', 'on_leave'];

    /**
     * @return array{
     *     today: string,
     *     month_label: string,
     *     range_label: string,
     *     days: array<int, array{date: string, number: string, weekday: string, is_today: bool, is_weekend: bool, is_past: bool, assigned: int}>,
     * }
     */
    public function forCurrentWeek(): array
    {
        // "Today" is resolved in the workforce timezone, then dropped back to a
        // plain calendar date: every date the models hand back is a naive
        // midnight, and mixing the two shunts a day across the boundary.
        $today = Carbon::parse(Carbon::now(config('workforce.timezone', 'Asia/Manila'))->toDateString());

        return Cache::remember(
            'dashboard.schedule-calendar.v1.'.$today->toDateString(),
            now()->addSeconds(60),
            fn (): array => $this->build($today),
        );
    }

    /** @return array<string, mixed> */
    private function build(Carbon $today): array
    {
        $start = $today->copy()->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->addDays(6);

        $assigned = $this->workforceOnly(ScheduleAssignment::query())
            ->where('status', 'scheduled')
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->get(['employee_id', 'work_date'])
            ->groupBy(fn (ScheduleAssignment $row): string => $row->work_date->toDateString())
            // Somebody rostered onto two shifts in a day is one person on the
            // floor, which is what the strip is counting.
            ->map(fn (Collection $rows): int => $rows->pluck('employee_id')->unique()->count());

        $days = collect(CarbonPeriod::create($start, $end))
            ->map(function ($date) use ($today, $assigned): array {
                $day = Carbon::instance($date);
                $key = $day->toDateString();

                return [
                    'date' => $key,
                    'number' => $day->format('j'),
                    'weekday' => $day->format('D'),
                    'is_today' => $day->isSameDay($today),
                    'is_weekend' => $day->isWeekend(),
                    'is_past' => $day->lessThan($today),
                    'assigned' => (int) $assigned->get($key, 0),
                ];
            })
            ->values()
            ->all();

        return [
            'today' => $today->toDateString(),
            // A week that straddles two months has to name both, or the strip
            // silently mislabels half its own numbers.
            'month_label' => $start->isSameMonth($end)
                ? $start->format('F Y')
                : $start->format('M').' – '.$end->format('M Y'),
            'range_label' => $start->format('M j').' – '.$end->format('M j, Y'),
            'days' => $days,
        ];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function workforceOnly(Builder $query): Builder
    {
        return $query->whereHas(
            'employee',
            fn (Builder $employee) => $employee->whereIn('employment_status', self::WORKFORCE_STATUSES),
        );
    }
}
