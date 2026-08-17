<?php

namespace App\Http\Requests\Schedule;

use App\Models\Position;
use App\Models\Shift;
use App\Services\Scheduling\SchedulePeriodService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RotationScheduleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $holidayDates = collect(preg_split('/[\s,]+/', (string) $this->input('holiday_dates_csv')))
            ->filter()
            ->values()
            ->all();
        $this->merge(['holiday_dates' => $holidayDates]);

        $period = $this->input('schedule_period');
        $anchor = $period === 'monthly' ? $this->input('period_month') : $this->input('period_start');
        if (! in_array($period, ['weekly', 'two_weeks', 'monthly'], true) || ! $anchor) {
            return;
        }

        try {
            [$start, $end] = app(SchedulePeriodService::class)->range($period, $anchor);
            $this->merge(['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()]);
        } catch (\Throwable) {
            // The field rules provide the validation response.
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('workforce.view');
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'employee_ids' => ['required', 'array', 'min:1', 'max:'.config('schedule.max_bulk_assignment_employees')],
            'employee_ids.*' => ['required', 'integer', 'distinct', Rule::exists('employees', 'id')->where('department_id', $this->input('department_id'))],
            'shift_ids' => ['required', 'array', 'min:1', 'max:10', $this->shiftPoolIsCoherent()],
            'shift_ids.*' => ['required', 'integer', 'distinct', 'exists:shifts,id'],
            'schedule_method' => ['nullable', Rule::in(['rotation', 'custom'])],
            'schedule_period' => ['required', Rule::in(['weekly', 'two_weeks', 'monthly'])],
            'period_start' => ['nullable', 'required_if:schedule_period,weekly,two_weeks', 'date'],
            'period_month' => ['nullable', 'required_if:schedule_period,monthly', 'date_format:Y-m'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'days_off_per_week' => ['nullable', 'integer', 'between:0,6'],
            'max_hours_per_week' => ['nullable', 'integer', 'between:1,168'],
            'night_shift_limit' => ['nullable', 'integer', 'between:0,7'],
            'max_consecutive_nights' => ['nullable', 'integer', 'between:1,7'],
            'minimum_rest_hours' => ['nullable', 'integer', 'between:1,48'],
            'overtime_allowed' => ['nullable', 'boolean'],
            'maximum_staff_per_shift' => ['nullable', 'integer', 'between:1,100'],
            'minimum_senior_per_shift' => ['nullable', 'integer', 'between:0,100'],
            'senior_rank_threshold' => ['nullable', 'integer', 'between:2,'.Position::MAX_SENIORITY_RANK],
            'holiday_dates_csv' => ['nullable', 'string', 'max:500'],
            'holiday_dates' => ['nullable', 'array', 'max:31'],
            'holiday_dates.*' => ['date', 'distinct'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * A pool is either one standalone shift or two or more rotating ones.
     *
     * An 8-to-5 office day covers the day it works by itself, so it needs no
     * partner and admits none: pairing it with a Night leg would ask the
     * assistant to roster one team across two patterns that cannot both hold.
     * A rotating leg is the opposite case — Morning alone leaves the afternoon
     * and the night unstaffed, so it needs at least one more.
     */
    private function shiftPoolIsCoherent(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $shiftIds = array_filter((array) $value);

            if ($shiftIds === []) {
                return;
            }

            $rotating = Shift::query()->whereKey($shiftIds)->pluck('is_rotating');

            if ($rotating->contains(true) && $rotating->contains(false)) {
                $fail('A standalone shift cannot be combined with a rotating one. Choose either the standalone shift on its own, or two or more rotating shifts.');

                return;
            }

            if ($rotating->count() > 1 && $rotating->every(fn ($isRotating) => ! $isRotating)) {
                $fail('A standalone shift covers a full working day on its own, so only one may be chosen.');

                return;
            }

            if ($rotating->count() === 1 && $rotating->first()) {
                $fail('Select at least two shifts — a rotating shift covers only part of the day.');
            }
        };
    }
}
