<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reserves one employee's PIN on one terminal.
 *
 * Whether that employee may be enrolled at all -- active, not archived, id
 * short enough for the device's PIN field -- is BiometricEnrollmentService's
 * decision, not this class's. Those rules decide what the roster is allowed to
 * contain and are enforced wherever an assignment comes from, including the
 * bulk action, so they live in one place rather than being restated here.
 */
class AssignBiometricPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('system-administrator') ?? false;
    }

    public function rules(): array
    {
        return [
            'biometric_device_id' => [
                'required', 'integer',
                Rule::exists('biometric_devices', 'id')->where('is_active', true),
            ],
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'biometric_device_id.exists' => 'That terminal is not registered, or is no longer active.',
        ];
    }
}
