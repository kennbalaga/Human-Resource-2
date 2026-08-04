<?php

namespace App\Http\Requests\Schedule;

use App\Services\Scheduling\SchedulePeriodService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkScheduleAssignmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $holidayDates = collect(preg_split('/[\s,]+/', (string) $this->input('holiday_dates_csv')))
            ->filter()
            ->values()
            ->all();
        $this->merge(['holiday_dates' => $holidayDates]);

        $period = $this->input('schedule_period');
        if (! in_array($period, ['weekly', 'two_weeks', 'monthly'], true)) {
            return;
        }

        $anchor = $period === 'monthly'
            ? $this->input('period_month')
            : $this->input('period_start');
        if (! $anchor) {
            return;
        }

        try {
            [$start, $end] = app(SchedulePeriodService::class)->range($period, $anchor);
            $this->merge([
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ]);
        } catch (\Throwable) {
            // The request rules return the validation error for an invalid date or month.
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
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'schedule_period' => ['nullable', Rule::in(['weekly', 'two_weeks', 'monthly'])],
            'period_start' => ['nullable', 'required_if:schedule_period,weekly,two_weeks', 'date'],
            'period_month' => ['nullable', 'required_if:schedule_period,monthly', 'date_format:Y-m'],
            'start_date' => ['bail', 'required', 'date'],
            'end_date' => [
                'bail',
                'required',
                'date',
                'after_or_equal:start_date',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->date('start_date') || ! $this->date('end_date')) {
                        return;
                    }

                    if ($this->date('start_date')->diffInDays($this->date('end_date')) >= config('schedule.max_bulk_assignment_days')) {
                        $fail('The bulk assignment may not exceed '.config('schedule.max_bulk_assignment_days').' days.');
                    }
                },
            ],
            'include_weekends' => ['nullable', 'boolean'],
            'days_off_per_week' => ['nullable', 'integer', 'between:0,6'],
            'max_hours_per_week' => ['nullable', 'integer', 'between:1,168'],
            'night_shift_limit' => ['nullable', 'integer', 'between:0,7'],
            'overtime_allowed' => ['nullable', 'boolean'],
            'minimum_staff_per_shift' => ['nullable', 'integer', 'between:1,100'],
            'holiday_dates_csv' => ['nullable', 'string', 'max:500'],
            'holiday_dates' => ['nullable', 'array', 'max:31'],
            'holiday_dates.*' => ['date', 'distinct'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
