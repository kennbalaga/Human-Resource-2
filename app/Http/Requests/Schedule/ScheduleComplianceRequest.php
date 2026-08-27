<?php

namespace App\Http\Requests\Schedule;

use App\Http\Requests\Concerns\ScopesToSupervisedDepartments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ScheduleComplianceRequest extends FormRequest
{
    use ScopesToSupervisedDepartments;

    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('hr.manage');
    }

    public function rules(): array
    {
        return [
            'department_id' => ['required', 'integer', 'exists:departments,id', ...$this->supervisedDepartmentRules()],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }
}
