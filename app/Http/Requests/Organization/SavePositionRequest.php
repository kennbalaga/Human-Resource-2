<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles()
            ->whereIn('slug', ['system-administrator', 'hr-manager'])
            ->exists() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => str($this->input('code'))->trim()->upper()->toString(),
            'title' => str($this->input('title'))->trim()->toString(),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        $position = $this->route('position');
        $departmentExists = Rule::exists('departments', 'id');

        if ($position === null || $this->integer('department_id') !== $position->department_id) {
            $departmentExists->where('is_active', true);
        }

        return [
            'department_id' => ['required', 'integer', $departmentExists],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/', Rule::unique('positions', 'code')->ignore($position?->id)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
