<?php

namespace App\Http\Requests\Schedule;

use App\Http\Requests\Concerns\ScopesToSupervisedDepartments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ScheduleAssignmentRequest extends FormRequest
{
    use ScopesToSupervisedDepartments;

    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('workforce.view');
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id', ...$this->supervisedEmployeeRules()],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'work_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'exclude_assignment_id' => ['nullable', 'integer', 'exists:schedule_assignments,id'],
            // Set only when this save follows an AI recommendation apply —
            // see ai-scheduling.js's applySelected(). Influence, not a
            // strict binding: the form fields may still be hand-edited
            // afterward before Save.
            'recommendation_id' => ['nullable', 'string', 'exists:schedule_recommendations,uuid'],
        ];
    }
}
