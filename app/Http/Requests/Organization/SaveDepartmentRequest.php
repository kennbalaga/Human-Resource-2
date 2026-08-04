<?php

namespace App\Http\Requests\Organization;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager'])->isNotEmpty() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => str($this->input('code'))->trim()->upper()->toString(),
            'name' => str($this->input('name'))->trim()->toString(),
            'category' => str($this->input('category', Department::CATEGORY_ADMINISTRATIVE))->trim()->lower()->toString(),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        $department = $this->route('department');

        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('departments', 'code')->ignore($department?->id)],
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->ignore($department?->id)],
            'category' => ['required', Rule::in(array_keys(Department::categories()))],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
