<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesWorkforce;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    use AuthorizesWorkforce;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requireManager($request->user());
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['nullable', 'in:active,inactive,terminated,on_leave'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $employees = Employee::query()->with(['user', 'department', 'position'])
            ->when($validated['department_id'] ?? null, fn ($query, $id) => $query->where('department_id', $id))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('employment_status', $status))
            ->orderBy('last_name')->paginate($validated['per_page'] ?? 25);

        return EmployeeResource::collection($employees);
    }

    public function show(Request $request, Employee $employee): EmployeeResource
    {
        $this->requireRead($request->user());
        abort_unless($this->canManage($request->user()) || $request->user()->employee?->is($employee), 403);

        return new EmployeeResource($employee->load(['user', 'department', 'position']));
    }
}
