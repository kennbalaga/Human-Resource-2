<?php

namespace App\Http\Requests\Schedule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AiScheduleDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::forUser($this->user())->allows('workforce.view');
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['ignored', 'rejected'])],
            'reason' => ['nullable', 'required_if:action,rejected', 'string', 'min:5', 'max:1000'],
        ];
    }
}
