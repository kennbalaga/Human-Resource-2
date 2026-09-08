<?php

namespace App\Http\Requests\Organization;

use App\Models\Position;
use App\Services\Organization\EmployeeNumberSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager'])->isNotEmpty() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'employee_number' => str($this->input('employee_number'))->trim()->upper()->toString(),
            'email' => str($this->input('email'))->trim()->lower()->toString(),
            'first_name' => str($this->input('first_name'))->trim()->toString(),
            'middle_name' => str($this->input('middle_name'))->trim()->toString() ?: null,
            'last_name' => str($this->input('last_name'))->trim()->toString(),
            'suffix' => str($this->input('suffix'))->trim()->toString() ?: null,
        ]);
    }

    public function rules(): array
    {
        $employee = $this->route('employee');
        $autoGenerate = $employee === null && app(EmployeeNumberSettings::class)->autoGenerateEnabled();
        $departmentExists = Rule::exists('departments', 'id');
        $positionExists = Rule::exists('positions', 'id');

        if ($employee === null || $this->integer('department_id') !== $employee->department_id) {
            $departmentExists->where('is_active', true);
        }

        if ($employee === null || $this->integer('position_id') !== $employee->position_id) {
            $positionExists->where('is_active', true);
        }

        return [
            'employee_number' => [Rule::excludeIf($employee !== null || $autoGenerate), 'required', 'string', 'max:50', 'regex:/^[A-Z0-9-]+$/', Rule::unique('employees', 'employee_number')],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($employee?->user_id)],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'department_id' => ['required', 'integer', $departmentExists],
            'position_id' => ['required', 'integer', $positionExists],
            'supervisor_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('employment_status', 'active')->whereNull('archived_at'), Rule::notIn(array_filter([$employee?->id]))],
            'employment_status' => ['required', Rule::in(['active', 'inactive', 'on_leave', 'terminated'])],
            'hire_date' => ['required', 'date'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $position = Position::query()->find($this->integer('position_id'));

            if ($position !== null && $position->department_id !== $this->integer('department_id')) {
                $validator->errors()->add('position_id', 'The selected position does not belong to the selected department.');
            }
        }];
    }
}
