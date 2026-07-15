<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'employee_id' => Str::upper(trim((string) $this->input('employee_id'))),
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string', 'max:50'],
            'email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:255'],
        ];
    }
}
