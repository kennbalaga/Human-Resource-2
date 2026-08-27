<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

trait AuthorizesWorkforce
{
    protected function canManage(User $user): bool
    {
        return Gate::forUser($user)->allows('workforce.view');
    }

    protected function requireManager(User $user): void
    {
        abort_unless($this->canManage($user) && $user->tokenCan('workforce:write'), 403, 'This token cannot manage workforce records.');
    }

    protected function requireRead(User $user): void
    {
        abort_unless($user->tokenCan('workforce:read'), 403, 'This token cannot read workforce records.');
    }

    /**
     * The record-level half of the same question. The methods above ask what
     * the token and the role allow; these ask whose records that reaches.
     *
     * A department head holds the same role as HR and would otherwise read and
     * write the whole hospital through the API even though the web screens now
     * stop at their own unit.
     */
    protected function requireManagerFor(User $user, ?Employee $employee): void
    {
        $this->requireManager($user);

        if ($user->supervises($employee)) {
            return;
        }

        $this->recordCrossDepartmentDenial($user, $employee, 'manage');

        abort(403, 'This record belongs to a department you do not supervise.');
    }

    /**
     * Read access to one record: the employee's own, or a supervisor's within
     * their unit.
     */
    protected function requireReadFor(User $user, ?Employee $employee): void
    {
        $this->requireRead($user);

        $ownRecord = $employee !== null && $employee->id === $user->employee?->id;

        if ($ownRecord || ($this->canManage($user) && $user->supervises($employee))) {
            return;
        }

        $this->recordCrossDepartmentDenial($user, $employee, 'read');

        abort(403, 'This record belongs to a department you do not supervise.');
    }

    /**
     * Narrow a listing to the departments this token's owner supervises.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    protected function constrainToSupervised(Builder $query, User $user, string $relation = 'employee'): Builder
    {
        return Employee::constrainRelatedQuery($query, $user, $relation);
    }

    private function recordCrossDepartmentDenial(User $user, ?Employee $employee, string $mode): void
    {
        Log::notice('HRMS blocked an API request outside the supervised department.', [
            'event' => 'authorization.cross_department_block',
            'channel' => 'api',
            'mode' => $mode,
            'user_id' => $user->id,
            'target_employee_id' => $employee?->id,
            'target_department_id' => $employee?->department_id,
        ]);
    }
}
