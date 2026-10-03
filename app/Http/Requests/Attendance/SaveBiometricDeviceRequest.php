<?php

namespace App\Http\Requests\Attendance;

use App\Models\BiometricDevice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registers or edits a terminal.
 *
 * This form is a security boundary, not a convenience. Registering a device is
 * what puts its serial on the punch endpoint's allowlist
 * (StoreBiometricPunchBatchRequest refuses any serial with no active row), so
 * what is accepted here decides which machines may post attendance at all.
 */
class SaveBiometricDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('system-administrator') ?? false;
    }

    public function rules(): array
    {
        $device = $this->route('biometricDevice');
        $deviceId = $device instanceof BiometricDevice ? $device->id : null;

        return [
            'office_location_id' => ['required', 'integer', Rule::exists('office_locations', 'id')],
            'code' => ['required', 'string', 'max:80', Rule::unique('biometric_devices', 'code')->ignore($deviceId)],
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', Rule::in(['zkteco', 'simulator'])],

            // Unique even though the column is not, because the punch endpoint
            // resolves a batch to one device by serial with firstOrFail(): two
            // active rows sharing a serial would make that non-deterministic
            // and attribute a ward's attendance to whichever row came back
            // first. A unique index would be better, but the table may already
            // hold the simulator's null, so this is the proportionate guard.
            'serial_number' => [
                'required_unless:provider,simulator', 'nullable', 'string', 'max:120',
                Rule::unique('biometric_devices', 'serial_number')->ignore($deviceId),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'serial_number.unique' => 'Another terminal is already registered with that serial number. The punch endpoint identifies a batch by serial, so it has to be unique.',
            'serial_number.required_unless' => 'A real terminal needs its serial number — it is what the bridge agent signs as.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // A serial read off a device label and pasted in carries whitespace
        // more often than not, and the punch endpoint compares it exactly.
        if (is_string($this->input('serial_number'))) {
            $this->merge(['serial_number' => trim($this->input('serial_number'))]);
        }
    }
}
