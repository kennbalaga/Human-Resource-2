<?php

namespace App\Http\Requests\Analytics;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class AnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager', 'department-head'])->isNotEmpty() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'date_from' => $this->input('date_from', now(config('workforce.timezone'))->startOfMonth()->toDateString()),
            'date_to' => $this->input('date_to', now(config('workforce.timezone'))->toDateString()),
        ]);
    }

    public function rules(): array
    {
        return [
            'date_from' => ['bail', 'required', 'date'],
            'date_to' => [
                'bail', 'required', 'date', 'after_or_equal:date_from',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->date('date_from') && $this->date('date_to') && $this->date('date_from')->diffInDays($this->date('date_to')) > config('workforce.analytics_max_days')) {
                        $fail('The analytics range may not exceed '.config('workforce.analytics_max_days').' days.');
                    }
                },
            ],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ];
    }
}
