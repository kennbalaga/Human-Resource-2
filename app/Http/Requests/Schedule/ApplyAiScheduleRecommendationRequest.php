<?php

namespace App\Http\Requests\Schedule;

use App\Http\Requests\Concerns\ScopesToSupervisedDepartments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ApplyAiScheduleRecommendationRequest extends FormRequest
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
            'department_id' => ['required', 'integer', 'exists:departments,id', ...$this->supervisedDepartmentRules()],
            'position_id' => ['required', 'integer', 'exists:positions,id'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'work_date' => ['required', 'date'],
        ];
    }
}
