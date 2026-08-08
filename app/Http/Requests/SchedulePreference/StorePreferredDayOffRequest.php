<?php

namespace App\Http\Requests\SchedulePreference;

use Illuminate\Foundation\Http\FormRequest;

class StorePreferredDayOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->employee !== null;
    }

    public function rules(): array
    {
        return [
            'preferred_date' => ['required', 'date', 'after:today'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
