<?php

namespace App\Http\Requests\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles()
            ->whereIn('slug', ['system-administrator', 'hr-manager'])
            ->exists() ?? false;
    }

    public function rules(): array
    {
        $shiftId = $this->route('shift')?->id;

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('shifts', 'code')->ignore($shiftId)],
            'name' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
