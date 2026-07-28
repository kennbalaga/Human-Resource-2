<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $query = trim((string) ($validated['q'] ?? ''));

        $employees = collect();
        $departments = collect();

        if ($query !== '') {
            $employees = Employee::query()
                ->with(['user', 'department', 'position'])
                ->where(function (Builder $employeeQuery) use ($query): void {
                    $employeeQuery
                        ->where('employee_number', 'like', "%{$query}%")
                        ->orWhere('first_name', 'like', "%{$query}%")
                        ->orWhere('last_name', 'like', "%{$query}%")
                        ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->where('email', 'like', "%{$query}%"));
                })
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

        return view('search.index', [
            'query' => $query,
            'employees' => $employees,
            'departments' => $departments,
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
        ]);
    }
}
