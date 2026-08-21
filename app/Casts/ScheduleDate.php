<?php

namespace App\Casts;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A plain 'date' cast parses in the app's default timezone (UTC), while
 * scheduling code throughout builds comparison boundaries via
 * Carbon::parse($x, config('schedule.timezone')) (Asia/Manila). Comparing
 * those via an instant-based method (betweenIncluded, lessThan, ...) against
 * a UTC-parsed work_date silently misaligns by the timezone offset — this
 * cast makes work_date parse in the same timezone as everything it's
 * compared against. Calendar-date output (toDateString()/format()) is
 * unaffected either way, since Carbon formats using the object's own
 * timezone regardless of which timezone it was parsed in.
 *
 * @implements CastsAttributes<Carbon, DateTimeInterface|string>
 */
class ScheduleDate implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, config('schedule.timezone'))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof DateTimeInterface ? Carbon::instance($value)->toDateString() : (string) $value;
    }
}
