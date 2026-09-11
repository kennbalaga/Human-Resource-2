<?php

namespace App\Http\Requests\Leave;

use App\Models\LeaveType;
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
            'satisfies_sil' => $this->boolean('satisfies_sil'),
            'eligible_gender' => $this->input('eligible_gender') ?: null,
            'requires_designation' => $this->input('requires_designation') ?: null,
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
            'category' => ['required', Rule::in(LeaveType::categories())],
            'annual_entitlement' => ['required', 'numeric', 'min:0', 'max:366'],
            'accrual_method' => ['required', Rule::in(LeaveType::accrualMethods())],
            'day_basis' => ['required', Rule::in(LeaveType::dayBases())],
            'max_carry_over' => ['required', 'numeric', 'min:0', 'max:366'],
            'requires_attachment' => ['required', 'boolean'],
            'eligible_gender' => ['nullable', Rule::in(LeaveType::genders())],
            'min_service_months' => ['required', 'integer', 'min:0', 'max:600'],
            'requires_designation' => ['nullable', Rule::in(LeaveType::designations())],
            'satisfies_sil' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $method = $this->input('accrual_method');
            $carryOver = (float) $this->input('max_carry_over');

            // Carry-over is a property of a yearly allowance. There is nothing
            // for a per-occasion or uncapped type to carry: no year opens with
            // credits, so a leftover balance cannot exist to roll forward.
            if ($method !== LeaveType::ACCRUAL_ANNUAL && $carryOver > 0) {
                $validator->errors()->add('max_carry_over', 'Only a yearly allowance can carry days over.');
            }

            if ($method === LeaveType::ACCRUAL_ANNUAL && $carryOver > (float) $this->input('annual_entitlement')) {
                $validator->errors()->add('max_carry_over', 'Carry-over cannot exceed the annual entitlement.');
            }

            if ($method === LeaveType::ACCRUAL_UNLIMITED && (float) $this->input('annual_entitlement') > 0) {
                $validator->errors()->add('annual_entitlement', 'An uncapped type holds no entitlement. Set it to 0.');
            }

            if ($method === LeaveType::ACCRUAL_PER_EVENT && (float) $this->input('annual_entitlement') <= 0) {
                $validator->errors()->add('annual_entitlement', 'A per-occasion type needs the maximum days one occasion may draw.');
            }
        }];
    }
}
