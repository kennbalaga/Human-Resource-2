<?php

namespace App\Http\Requests\Schedule;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RecurringScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('workforce.view');
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('recurrence_type') === 'daily') {
            $this->merge(['weekdays' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
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

                    if ($this->date('start_date')->diffInDays($this->date('end_date')) > config('schedule.max_recurrence_days')) {
                        $fail('The recurring schedule may not exceed '.config('schedule.max_recurrence_days').' days.');
                    }
                },
            ],
            'recurrence_type' => ['required', Rule::in(['daily', 'weekly'])],
            'weekdays' => ['nullable', 'required_if:recurrence_type,weekly', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'interval_weeks' => ['required', 'integer', 'between:1,4'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
