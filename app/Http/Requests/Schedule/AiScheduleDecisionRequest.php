<?php

namespace App\Http\Requests\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiScheduleDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles()->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])->exists() ?? false;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['ignored', 'rejected'])],
            'reason' => ['nullable', 'required_if:action,rejected', 'string', 'min:5', 'max:1000'],
        ];
    }
}
