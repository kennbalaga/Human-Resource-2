<?php

namespace App\Services\Scheduling;

use Carbon\Carbon;

class SchedulePeriodService
{
    /**
     * @return array{Carbon, Carbon}
     */
    public function range(string $period, string $anchor): array
    {
        $start = Carbon::parse($anchor, config('schedule.timezone'))->startOfDay();

        return match ($period) {
            'weekly' => [$start, $start->copy()->addDays(6)],
            'two_weeks' => [$start, $start->copy()->addDays(13)],
            'monthly' => [$start->copy()->startOfMonth(), $start->copy()->endOfMonth()],
        };
    }
}
