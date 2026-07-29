<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organization\SaveDepartmentRequest;
use App\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'in:'.implode(',', array_keys(Department::categories()))],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        $departments = Department::query()
            ->withCount(['employees', 'positions'])
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->where(fn (Builder $nested) => $nested->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('is_active', $status === 'active'))
            ->orderByRaw("case category when 'clinical' then 1 when 'administrative' then 2 when 'support' then 3 else 4 end")
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return view('departments.index', [
            'departments' => $departments,
            'filters' => $filters,
            'categories' => Department::categories(),
            'canManage' => $this->canManage($request),
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
        ]);
    }

    public function create(Request $request): View
    {
        $this->requireManager($request);

        return view('departments.create', [
            'categories' => Department::categories(),
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
        ]);
    }

    public function store(SaveDepartmentRequest $request): RedirectResponse
    {
        $department = Department::query()->create($request->validated());

        return redirect()->route('departments.edit', $department)->with('success', 'Department created successfully.');
    }

    public function edit(Request $request, Department $department): View
    {
        $this->requireManager($request);

        return view('departments.edit', [
            'department' => $department->loadCount(['employees', 'positions']),
            'categories' => Department::categories(),
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
        ]);
    }

    public function update(SaveDepartmentRequest $request, Department $department): RedirectResponse
    {
        $department->update($request->validated());

        return back()->with('success', 'Department updated successfully.');
    }

    private function canManage(Request $request): bool
    {
        return $request->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager'])->exists();
    }

    private function requireManager(Request $request): void
    {
        abort_unless($this->canManage($request), 403);
    }
}
