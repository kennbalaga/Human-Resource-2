<?php

namespace App\Http\Requests\Attendance;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class AttendanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->roles()
            ->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])
            ->exists() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $today = now()->timezone(config('attendance.default_location.timezone'));

        $this->merge([
            'date_from' => $this->input('date_from', $today->copy()->startOfMonth()->toDateString()),
            'date_to' => $this->input('date_to', $today->toDateString()),
        ]);
    }

    public function rules(): array
    {
        return [
            'date_from' => ['bail', 'required', 'date'],
            'date_to' => [
                'bail',
                'required',
                'date',
                'after_or_equal:date_from',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->date('date_from') || ! $this->date('date_to')) {
                        return;
                    }

                    if ($this->date('date_from')->diffInDays($this->date('date_to')) > config('attendance.report_max_days')) {
                        $fail('The report range may not exceed '.config('attendance.report_max_days').' days.');
                    }
                },
            ],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['nullable', 'string', 'in:present,late'],
            'approval_status' => ['nullable', 'string', 'in:pending,approved,rejected'],
            'capture_method' => ['nullable', 'string', 'in:manual,biometric,mixed'],
        ];
    }
}
