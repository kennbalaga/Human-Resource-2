<?php

namespace App\Services\Schedule;

use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * One employee's own schedule, shaped for a phone.
 *
 * The calendar page answers "who is working this month" and is built for a
 * grid. This answers "what am I doing, and who is on with me" for one day at a
 * time, which is the question somebody asks standing in a corridor.
 *
 * It reads nothing the employee could not already reach. Their own assignments
 * and rest days are already theirs; the colleagues are the same set, scoped the
 * same way, that the swap page has shown clinical staff since before this
 * existed — same department, same shift, never themselves.
 */
class MyScheduleService
{
    /** How far the "next two weeks" list looks. */
    private const HORIZON_DAYS = 14;

    /**
     * @return array<string, mixed>
     */
    public function forEmployee(Employee $employee, ?string $focusDate = null): array
    {
        $timezone = config('schedule.timezone');
        $today = Carbon::now($timezone)->startOfDay();

        $focus = $focusDate !== null
            ? Carbon::parse($focusDate, $timezone)->startOfDay()
            : $today->copy();

        // Monday-first, matching the calendar's own week and the strip's M T W
        // T F S S labels.
        $weekStart = $focus->copy()->startOfWeek(Carbon::MONDAY);
        $rangeStart = $weekStart->copy()->min($today);
        $rangeEnd = $today->copy()->addDays(self::HORIZON_DAYS)->max($weekStart->copy()->addDays(6));

        $assignments = ScheduleAssignment::query()
            ->with(['shift', 'room'])
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get()
            ->keyBy(fn (ScheduleAssignment $assignment): string => $assignment->work_date->toDateString());

        $dayOffs = ScheduleDayOff::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->pluck('work_date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->flip();

        return [
            'focus_date' => $focus->toDateString(),
            'month_label' => $focus->format('F Y'),
            'is_today' => $focus->isSameDay($today),
            'today_date' => $today->toDateString(),
            'week' => $this->week($weekStart, $today, $focus, $assignments, $dayOffs),
            'day' => $this->day($focus, $today, $assignments->get($focus->toDateString()), $dayOffs->has($focus->toDateString())),
            'colleagues' => $this->colleagues($employee, $assignments->get($focus->toDateString())),
            'upcoming' => $this->upcoming($today, $assignments, $dayOffs),
        ];
    }

    /**
     * The seven circles. Each carries only what the strip draws: the letter,
     * the number, and which of the three states the day is in.
     *
     * @param  Collection<string, ScheduleAssignment>  $assignments
     * @param  Collection<string, int>  $dayOffs
     * @return list<array<string, mixed>>
     */
    private function week(Carbon $weekStart, Carbon $today, Carbon $focus, Collection $assignments, Collection $dayOffs): array
    {
        return collect(range(0, 6))
            ->map(function (int $offset) use ($weekStart, $today, $focus, $assignments, $dayOffs): array {
                $date = $weekStart->copy()->addDays($offset);
                $key = $date->toDateString();
                $assignment = $assignments->get($key);

                return [
                    'date' => $key,
                    'letter' => $date->format('D')[0],
                    'day' => $date->day,
                    'is_today' => $date->isSameDay($today),
                    'is_focus' => $date->isSameDay($focus),
                    'is_past' => $date->lt($today),
                    // Three states, because the strip has one dot to say them
                    // with: rostered, a rest day, or nothing planned.
                    'state' => match (true) {
                        $assignment !== null => 'shift',
                        $dayOffs->has($key) => 'day-off',
                        default => 'none',
                    },
                    'shift_color' => $assignment?->shift?->color,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function day(Carbon $focus, Carbon $today, ?ScheduleAssignment $assignment, bool $isDayOff): array
    {
        return [
            'label' => $focus->format('l, j F'),
            'is_today' => $focus->isSameDay($today),
            'is_past' => $focus->lt($today),
            'state' => match (true) {
                $assignment !== null => 'shift',
                $isDayOff => 'day-off',
                default => 'none',
            },
            'shift_name' => $assignment?->shift?->name,
            'hours' => $assignment?->shift !== null
                ? Carbon::parse($assignment->shift->start_time)->format('g:i A').' – '.Carbon::parse($assignment->shift->end_time)->format('g:i A')
                : null,
            'room' => $assignment?->room?->name,
            'color' => $assignment?->shift?->color,
        ];
    }

    /**
     * Who else is on this shift.
     *
     * Same department, same date, same shift, never the reader — the scope the
     * swap page already uses. Withheld entirely from non-clinical staff,
     * because that is where the existing line is drawn and this is not the
     * place to move it.
     *
     * @return list<array<string, string>>
     */
    private function colleagues(Employee $employee, ?ScheduleAssignment $assignment): array
    {
        if ($assignment === null || ! $employee->canUseShiftSwaps() || $employee->department_id === null) {
            return [];
        }

        return ScheduleAssignment::query()
            ->with('employee.position')
            ->where('work_date', $assignment->work_date->toDateString())
            ->where('shift_id', $assignment->shift_id)
            ->where('status', 'scheduled')
            ->where('employee_id', '!=', $employee->id)
            ->whereHas('employee', fn ($query) => $query->where('department_id', $employee->department_id))
            ->limit(8)
            ->get()
            ->map(fn (ScheduleAssignment $row): array => [
                'id' => $row->employee_id,
                'name' => $row->employee?->full_name ?? 'Unknown',
                'short_name' => $this->shortName($row->employee?->full_name ?? 'Unknown'),
                'initials' => $this->initials($row->employee?->full_name ?? '?'),
                'position' => $row->employee?->position?->title ?? 'Staff',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, ScheduleAssignment>  $assignments
     * @param  Collection<string, int>  $dayOffs
     * @return list<array<string, mixed>>
     */
    private function upcoming(Carbon $today, Collection $assignments, Collection $dayOffs): array
    {
        return collect(range(1, self::HORIZON_DAYS))
            ->map(function (int $offset) use ($today, $assignments, $dayOffs): ?array {
                $date = $today->copy()->addDays($offset);
                $key = $date->toDateString();
                $assignment = $assignments->get($key);
                $isDayOff = $dayOffs->has($key);

                // Days with nothing on them are left out rather than listed as
                // empty: a fortnight of "no shift" rows is not a schedule.
                if ($assignment === null && ! $isDayOff) {
                    return null;
                }

                return [
                    'date' => $key,
                    'label' => $date->format('D j'),
                    'state' => $assignment !== null ? 'shift' : 'day-off',
                    'what' => $assignment?->shift !== null
                        ? Carbon::parse($assignment->shift->start_time)->format('g:i A').' – '.Carbon::parse($assignment->shift->end_time)->format('g:i A')
                        : 'Rest day',
                    'shift_name' => $assignment?->shift?->name,
                    'color' => $assignment?->shift?->color,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => strtoupper(substr($part, 0, 1)))
            ->implode('');
    }

    /** "Josef Dela Cruz" reads as "Josef D." on a 44px chip. */
    private function shortName(string $name): string
    {
        $parts = collect(explode(' ', trim($name)))->filter()->values();

        if ($parts->count() < 2) {
            return $parts->first() ?? $name;
        }

        return $parts->first().' '.strtoupper(substr($parts->last(), 0, 1)).'.';
    }
}
