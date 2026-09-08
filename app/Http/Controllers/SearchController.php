<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function index(Request $request): View|JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $query = trim((string) ($validated['q'] ?? ''));

        $employees = collect();
        $departments = collect();
        // Matching on the email column turns this box into a confirmation
        // oracle: type an address, learn whether it belongs to staff here. The
        // directory is meant to answer "who is this person and where do they
        // work", which name and employee number already do, so the email match
        // is kept for the roles that handle records anyway.
        $canSearchEmail = Gate::forUser($request->user())->allows('workforce.view');

        if ($query !== '') {
            $employees = Employee::query()
                ->with(['user', 'department', 'position'])
                ->matchingSearch($query, $canSearchEmail)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->limit(8)
                ->get();

            $departments = Department::query()
                ->withCount(['employees', 'positions'])
                ->where(fn (Builder $departmentQuery) => $departmentQuery
                    ->where('name', 'like', "%{$query}%")
                    ->orWhere('code', 'like', "%{$query}%"))
                ->orderBy('name')
                ->limit(8)
                ->get();
        }

        if ($request->wantsJson()) {
            return response()->json([
                'query' => $query,
                'employees' => $employees->map(fn (Employee $employee) => [
                    'name' => $employee->full_name,
                    'number' => $employee->employee_number,
                    'department' => $employee->department?->name,
                    'position' => $employee->position?->title,
                    // Search still finds a former colleague on purpose — that
                    // is how anyone reaches their record afterwards. Saying so
                    // here is what keeps it from reading as an ordinary hit
                    // that mysteriously cannot be scheduled.
                    'archived' => $employee->isArchived(),
                    'url' => route('employees.show', $employee),
                ])->values(),
                'departments' => $departments->map(fn (Department $department) => [
                    'name' => $department->name,
                    'employeesCount' => $department->employees_count,
                    'positionsCount' => $department->positions_count,
                    'url' => route('departments.index', ['search' => $department->name]),
                ])->values(),
                'directoryUrl' => $employees->isNotEmpty() ? route('employees.index', ['search' => $query]) : null,
                'departmentsUrl' => $departments->isNotEmpty() ? route('departments.index', ['search' => $query]) : null,
            ]);
        }

        return view('search.index', [
            'query' => $query,
            'employees' => $employees,
            'departments' => $departments,
            'canSearchEmail' => $canSearchEmail,
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }
}
