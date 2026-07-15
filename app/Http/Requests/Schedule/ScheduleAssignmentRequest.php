<?php

namespace App\Http\Requests\Schedule;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles()
            ->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])
            ->exists() ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'work_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'exclude_assignment_id' => ['nullable', 'integer', 'exists:schedule_assignments,id'],
        ];
    }
}
