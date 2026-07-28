<?php

namespace App\Http\Requests\Schedule;

use App\Services\Scheduling\SchedulePeriodService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RotationScheduleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
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
        return $this->user()?->roles()
            ->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])
            ->exists() ?? false;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'employee_ids' => ['required', 'array', 'min:1', 'max:'.config('schedule.max_bulk_assignment_employees')],
            'employee_ids.*' => ['required', 'integer', 'distinct', Rule::exists('employees', 'id')->where('department_id', $this->input('department_id'))],
            'shift_ids' => ['required', 'array', 'min:2', 'max:10'],
            'shift_ids.*' => ['required', 'integer', 'distinct', 'exists:shifts,id'],
            'schedule_period' => ['required', Rule::in(['weekly', 'two_weeks', 'monthly'])],
            'period_start' => ['nullable', 'required_if:schedule_period,weekly,two_weeks', 'date'],
            'period_month' => ['nullable', 'required_if:schedule_period,monthly', 'date_format:Y-m'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
