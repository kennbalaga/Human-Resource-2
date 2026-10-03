<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reserves a PIN for every active employee on one terminal.
 *
 * The confirmation flag is required rather than cosmetic: this writes a row for
 * every active employee in the hospital, and the page shows the count before
 * the button is pressed. Re-running it is harmless by design -- captured
 * templates stay captured -- but an accidental press should still not be one
 * click away.
 */
class BulkAssignBiometricPinsRequest extends FormRequest
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
            'confirm' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm.accepted' => 'Confirm the bulk assignment before it runs.',
            'biometric_device_id.exists' => 'That terminal is not registered, or is no longer active.',
        ];
    }
}
