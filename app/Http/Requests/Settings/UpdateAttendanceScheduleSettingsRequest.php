<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttendanceScheduleSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('system-administrator') ?? false;
    }

    public function rules(): array
    {
        return [
            'early_window_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'late_bind_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'schedule_aware' => ['boolean'],
            'enforce_published_shift' => ['boolean'],
        ];
    }
}
