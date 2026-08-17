<?php

namespace App\Http\Requests\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('hr.view');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'color' => ['required', 'in:#176B43,#2F80ED,#8B5CF6,#334155,#D97706,#DC2626'],
            'is_active' => ['nullable', 'boolean'],
            'is_rotating' => ['nullable', 'boolean'],
        ];
    }
}
