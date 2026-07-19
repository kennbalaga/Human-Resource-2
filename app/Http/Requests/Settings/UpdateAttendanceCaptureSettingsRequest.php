<?php

namespace App\Http\Requests\Settings;

use App\Services\AttendanceCaptureSettings;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendanceCaptureSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('system-administrator') ?? false;
    }

    public function rules(): array
    {
        return [
            'capture_mode' => ['required', Rule::in(AttendanceCaptureSettings::MODES)],
            'manual_mode_reason' => [
                Rule::requiredIf($this->input('capture_mode') === AttendanceCaptureSettings::EMERGENCY_MANUAL),
                'nullable',
                'string',
                'min:5',
                'max:1000',
            ],
            'manual_mode_expires_at' => [
                'bail',
                Rule::requiredIf($this->input('capture_mode') === AttendanceCaptureSettings::EMERGENCY_MANUAL),
                'nullable',
                'date',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value && ! Carbon::parse((string) $value, config('workforce.timezone'))->isFuture()) {
                        $fail('The emergency mode expiry must be a future Philippine time.');
                    }
                },
            ],
        ];
    }
}
