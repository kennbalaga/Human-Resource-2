<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimulateBiometricScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('system-administrator') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn (Builder $query) => $query->where('employment_status', 'active')),
            ],
            'event_type' => ['required', Rule::in(['check_in', 'check_out'])],
        ];
    }
}
