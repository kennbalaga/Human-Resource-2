<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * The record-level half of workforce authorisation.
 *
 * The role gates say whether an account may use a supervisory screen; these
 * helpers say whether it may act on the employee in front of it. Department
 * heads hold the same roles as HR but run one unit, so without this every
 * approval, export and download reaches the whole hospital.
 *
 * A refusal is logged the way EnforceReadOnlyRole logs its own, because a head
 * reaching outside their unit is worth seeing in the audit trail whether it was
 * a stale bookmark or somebody trying record ids by hand.
 */
trait ScopesWorkforceAccess
{
    protected function requireSupervision(Request $request, ?Employee $employee, string $ability = 'workforce.view.record'): void
    {
        if (Gate::forUser($request->user())->allows($ability, [$employee])) {
            return;
        }

        $this->recordCrossDepartmentDenial($request, $employee, $ability);

        abort(403, 'This record belongs to a department you do not supervise.');
    }

    protected function supervises(Request $request, ?Employee $employee): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->supervises($employee);
    }

    /**
     * Departments this account may pick from in a filter. Null means every
     * department, matching User::supervisedDepartmentIds().
     *
     * @return array<int, int>|null
     */
    protected function supervisedDepartmentIds(Request $request): ?array
    {
        $user = $request->user();

        return $user instanceof User ? $user->supervisedDepartmentIds() : [];
    }

    private function recordCrossDepartmentDenial(Request $request, ?Employee $employee, string $ability): void
    {
        Log::notice('HRMS blocked a workforce request outside the supervised department.', [
            'event' => 'authorization.cross_department_block',
            'ability' => $ability,
            'route_name' => $request->route()?->getName(),
            'method' => $request->method(),
            'path' => $request->path(),
            'user_id' => $request->user()?->id,
            'target_employee_id' => $employee?->id,
            'target_department_id' => $employee?->department_id,
            'ip_address' => $request->ip(),
            'request_id' => $request->headers->get('X-Request-ID'),
        ]);
    }
}
