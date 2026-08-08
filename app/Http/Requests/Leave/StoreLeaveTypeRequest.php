<?php

namespace App\Http\Requests\Leave;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('hr.view');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => str($this->input('code'))->trim()->upper()->toString(),
            'name' => str($this->input('name'))->trim()->toString(),
            'requires_attachment' => $this->boolean('requires_attachment'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/', Rule::unique('leave_types', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'annual_entitlement' => ['required', 'numeric', 'min:0', 'max:366'],
            'max_carry_over' => ['required', 'numeric', 'min:0', 'max:366'],
            'requires_attachment' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ((float) $this->input('max_carry_over') > (float) $this->input('annual_entitlement')) {
                $validator->errors()->add('max_carry_over', 'Carry-over cannot exceed the annual entitlement.');
            }
        }];
    }
}
