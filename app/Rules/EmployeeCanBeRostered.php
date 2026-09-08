<?php

namespace App\Rules;

use App\Models\Employee;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses work handed to somebody who has left.
 *
 * The pickers already leave archived and terminated employees out, so the only
 * way one of them reaches a roster is through a request the form did not build:
 * a page left open while HR filed the record away, a draft assembled last week
 * and published today, an id typed by hand. Each of those ends with a shift in
 * the name of a person who is not coming, and nobody finds out until the shift
 * is empty.
 *
 * The message names the reason rather than "the selected employee id is
 * invalid", because the reader is looking at a roster of thirty names and needs
 * to know which one to take out and why.
 */
class EmployeeCanBeRostered implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $employee = Employee::query()->find($value);

        // A missing employee is somebody else's rule to report: every caller
        // pairs this with an exists rule, and two messages for one id would
        // just be noise.
        if ($employee === null) {
            return;
        }

        if ($employee->isArchived()) {
            $fail($employee->full_name.' is archived and can no longer be given work. Restore the record first.');

            return;
        }

        if ($employee->employment_status === 'terminated') {
            $fail($employee->full_name.' is terminated and can no longer be given work.');
        }
    }
}
