<?php

namespace App\Http\Requests\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A roster exactly as the nursing office left it on screen.
 */
class RosterDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager', 'department-head'])->isNotEmpty() ?? false;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string', 'max:500'],

            'entries' => ['present', 'array', 'max:'.config('schedule.max_roster_entries', 2000)],
            'entries.*.employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where('department_id', $this->input('department_id')),
            ],
            // Null marks a rest day, which is a deliberate roster decision rather
            // than the absence of one.
            'entries.*.shift_id' => ['present', 'nullable', 'integer', 'exists:shifts,id'],
            'entries.*.work_date' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'entries.*.employee_id.exists' => 'A rostered employee does not belong to the selected department.',
        ];
    }
}
