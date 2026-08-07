<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organization\SaveDepartmentRequest;
use App\Http\Requests\Organization\SaveShiftRequirementsRequest;
use App\Models\Department;
use App\Models\Shift;
use App\Services\Scheduling\StaffingRequirementService;
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
            'canManage' => $this->canWrite($request),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }

    public function create(Request $request): View
    {
        $this->requireManager($request);

        return view('departments.create', [
            'categories' => Department::categories(),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
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
            'shifts' => Shift::query()->where('is_active', true)->orderBy('start_time')->get(),
            'requirements' => $department->shiftRequirements()->get()->keyBy('shift_id'),
            'derivedMinimum' => $department->derivedMinimumStaffPerShift(),
            'derivationSummary' => app(StaffingRequirementService::class)->derivationSummary($department),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }

    public function update(SaveDepartmentRequest $request, Department $department): RedirectResponse
    {
        $department->update($request->validated());

        return back()->with('success', 'Department updated successfully.');
    }

    /**
     * Record what each shift of this unit must be staffed to. Saved apart from the
     * department details so a coverage change reads as its own decision.
     */
    public function updateShiftRequirements(SaveShiftRequirementsRequest $request, Department $department): RedirectResponse
    {
        foreach ($request->validated()['requirements'] as $shiftId => $requirement) {
            $minimumStaff = $requirement['minimum_staff'] ?? null;

            $department->shiftRequirements()->updateOrCreate(
                ['shift_id' => $shiftId],
                [
                    'minimum_staff' => $minimumStaff === '' ? null : $minimumStaff,
                    'minimum_senior' => (int) ($requirement['minimum_senior'] ?? 0),
                ],
            );
        }

        return back()->with('success', 'Shift coverage standard updated.');
    }

    private function canManage(Request $request): bool
    {
        return $request->user()->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager'])->isNotEmpty();
    }

    private function canWrite(Request $request): bool
    {
        return $this->canManage($request) && $request->user()->canManageData();
    }

    private function requireManager(Request $request): void
    {
        abort_unless($this->canWrite($request), 403);
    }
}
