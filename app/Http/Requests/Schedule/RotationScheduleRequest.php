<?php

namespace App\Http\Requests\Schedule;

use App\Models\Position;
use App\Services\Scheduling\SchedulePeriodService;
use Illuminate\Foundation\Http\FormRequest;
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
        return $this->user()?->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager', 'department-head'])->isNotEmpty() ?? false;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'employee_ids' => ['required', 'array', 'min:1', 'max:'.config('schedule.max_bulk_assignment_employees')],
            'employee_ids.*' => ['required', 'integer', 'distinct', Rule::exists('employees', 'id')->where('department_id', $this->input('department_id'))],
            'shift_ids' => ['required', 'array', 'min:2', 'max:10'],
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
            'overtime_allowed' => ['nullable', 'boolean'],
            'minimum_staff_per_shift' => ['nullable', 'integer', 'between:1,100'],
            'minimum_senior_per_shift' => ['nullable', 'integer', 'between:0,100'],
            'senior_rank_threshold' => ['nullable', 'integer', 'between:2,'.Position::MAX_SENIORITY_RANK],
            'holiday_dates_csv' => ['nullable', 'string', 'max:500'],
            'holiday_dates' => ['nullable', 'array', 'max:31'],
            'holiday_dates.*' => ['date', 'distinct'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
