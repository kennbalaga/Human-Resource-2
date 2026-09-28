<?php

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class PayslipFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Three months back by default. Timesheets are weekly, so a single month
     * would open on four or five payslips and hide the one from last month an
     * employee is most likely to come looking for.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'date_from' => $this->input('date_from', now(config('workforce.timezone'))->subMonths(2)->startOfMonth()->toDateString()),
            'date_to' => $this->input('date_to', now(config('workforce.timezone'))->endOfMonth()->toDateString()),
        ]);
    }

    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ];
    }
}
