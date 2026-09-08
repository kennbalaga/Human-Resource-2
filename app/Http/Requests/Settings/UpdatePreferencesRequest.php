<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['timezone' => 'Asia/Manila']);

        foreach (['compact_navigation', 'reduce_motion'] as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
    }

    public function rules(): array
    {
        return [
            'timezone' => ['required', Rule::in(['Asia/Manila'])],
            'theme' => ['required', Rule::in(['light', 'dark', 'system'])],
            'compact_navigation' => ['required', 'boolean'],
            'reduce_motion' => ['required', 'boolean'],
        ];
    }
}
