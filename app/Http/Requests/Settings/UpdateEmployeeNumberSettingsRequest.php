<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('system-administrator') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'auto_generate' => $this->boolean('auto_generate'),
        ]);
    }

    public function rules(): array
    {
        return [
            'auto_generate' => ['required', 'boolean'],
        ];
    }
}
