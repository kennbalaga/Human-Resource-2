<?php

namespace App\Http\Requests\Concerns;

use App\Models\Employee;
use App\Models\User;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Validation rules that keep a request inside the departments its author
 * supervises.
 *
 * The role gates in `authorize()` decide whether an account may write schedules
 * at all; these rules decide whose. They belong on the request rather than in
 * the controller because the ids they check — `employee_id`, `department_id` —
 * arrive in the body, and a request that names somebody else's ward is invalid
 * input, not merely a forbidden action.
 */
trait ScopesToSupervisedDepartments
{
    /**
     * Restrict a `department_id` field to the units this account supervises.
     *
     * @return array<int, mixed>
     */
    protected function supervisedDepartmentRules(): array
    {
        $departmentIds = $this->supervisedDepartmentIdsForRequest();

        if ($departmentIds === null) {
            return [];
        }

        return [Rule::in($departmentIds)];
    }

    /**
     * Restrict an `employee_id` field to staff in those units. Expressed as an
     * exists() over the department column so an id that does not resolve and an
     * id in the wrong ward fail the same way, telling a prober nothing.
     *
     * @return array<int, mixed>
     */
    protected function supervisedEmployeeRules(): array
    {
        $departmentIds = $this->supervisedDepartmentIdsForRequest();

        if ($departmentIds === null) {
            return [];
        }

        return [Rule::exists('employees', 'id')->whereIn('department_id', $departmentIds)];
    }

    /**
     * The same check as a closure, for the nested `entries.*.employee_id` shape
     * where a whereIn on the outer rule cannot reach.
     */
    protected function supervisedEmployeeCallback(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $departmentIds = $this->supervisedDepartmentIdsForRequest();

            if ($departmentIds === null || $value === null) {
                return;
            }

            $supervised = Employee::query()
                ->whereKey($value)
                ->whereIn('department_id', $departmentIds)
                ->exists();

            if (! $supervised) {
                $fail('That employee is not in a department you supervise.');
            }
        };
    }

    /**
     * @return array<int, int>|null
     */
    private function supervisedDepartmentIdsForRequest(): ?array
    {
        $user = $this->user();

        return $user instanceof User ? $user->supervisedDepartmentIds() : [];
    }
}
