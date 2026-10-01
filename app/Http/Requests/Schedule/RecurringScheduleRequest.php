<?php

namespace App\Http\Requests\Schedule;

use App\Http\Requests\Concerns\ScopesToSupervisedDepartments;
use App\Rules\EmployeeCanBeRostered;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RecurringScheduleRequest extends FormRequest
{
    use ScopesToSupervisedDepartments;

    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('workforce.view');
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('recurrence_type') === 'daily') {
            $this->merge(['weekdays' => null]);
        }

        // A series ended by a count has no end date to post, but everything
        // downstream -- the length cap, the preload windows, the stored row --
        // is written in terms of one. Fill it with the furthest a series is
        // allowed to run and let the count stop the dates short of it, so the
        // count is the only new idea in the request rather than a second way
        // of expressing a range.
        if ($this->input('end_mode') === 'after' && $this->filled('start_date')) {
            $this->merge([
                'end_date' => $this->date('start_date')
                    ?->addDays((int) config('schedule.max_recurrence_days'))
                    ->toDateString(),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id', new EmployeeCanBeRostered, ...$this->supervisedEmployeeRules()],
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
            // How the series stops: on a date, or after a number of shifts.
            // Absent, it is a date, which is what every caller before this
            // option existed was already sending.
            'end_mode' => ['sometimes', Rule::in(['on', 'after'])],
            'occurrences' => ['exclude_unless:end_mode,after', 'required', 'integer', 'between:1,'.config('schedule.max_recurrence_days')],
            'recurrence_type' => ['required', Rule::in(['daily', 'weekly'])],
            'weekdays' => ['nullable', 'required_if:recurrence_type,weekly', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'interval_weeks' => ['required', 'integer', 'between:1,4'],
            'notes' => ['nullable', 'string', 'max:500'],
            // Leave out the dates the preview listed as skipped and create the
            // rest, instead of refusing the whole series over one clash.
            'skip_conflicts' => ['sometimes', 'boolean'],
            // Set by "Repeat weekly" on the assign-a-shift form, so the
            // read-back names it as a weekly shift and offers that form again.
            'origin' => ['sometimes', 'nullable', Rule::in(['assignment'])],
        ];
    }
}
