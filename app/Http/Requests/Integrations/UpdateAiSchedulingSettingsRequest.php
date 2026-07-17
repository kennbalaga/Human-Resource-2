<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiSchedulingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('system-administrator') ?? false;
    }

    public function rules(): array
    {
        return [
            'assistant_enabled' => ['nullable', 'boolean'],
            'gemini_explanations_enabled' => ['nullable', 'boolean'],
        ];
    }
}
