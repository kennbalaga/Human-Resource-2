<?php

namespace App\Http\Requests\Leave;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeaveFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['year' => $this->input('year', now(config('workforce.timezone'))->year)]);
    }

    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:2020,2100'],
            'status' => ['nullable', Rule::in(LeaveRequest::FILTERABLE_STATUSES)],
            'leave_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'date' => ['nullable', 'date'],
        ];
    }
}
