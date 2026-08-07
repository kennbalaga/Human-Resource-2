<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organization\SavePositionRequest;
use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PositionController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        $positions = Position::query()
            ->with('department')
            ->withCount('employees')
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->where(fn (Builder $nested) => $nested->where('title', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int $departmentId) => $query->where('department_id', $departmentId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('is_active', $status === 'active'))
            ->orderBy('title')
            ->paginate(15)
            ->withQueryString();

        return view('positions.index', [
            'positions' => $positions,
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(),
            'filters' => $filters,
            'canManage' => $this->canWrite($request),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }

    public function create(Request $request): View
    {
        $this->requireManager($request);

        return view('positions.create', $this->formData($request));
    }

    public function store(SavePositionRequest $request): RedirectResponse
    {
        $position = Position::query()->create($request->validated());

        return redirect()->route('positions.edit', $position)->with('success', 'Position created successfully.');
    }

    public function edit(Request $request, Position $position): View
    {
        $this->requireManager($request);

        return view('positions.edit', $this->formData($request, $position) + [
            'position' => $position->loadCount('employees'),
        ]);
    }

    public function update(SavePositionRequest $request, Position $position): RedirectResponse
    {
        $position->update($request->validated());

        return back()->with('success', 'Position updated successfully.');
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, ?Position $position = null): array
    {
        return [
            'departments' => Department::query()
                ->where(fn (Builder $query) => $query->where('is_active', true)->when($position, fn (Builder $nested) => $nested->orWhere('id', $position->department_id)))
                ->orderBy('name')
                ->get(),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ];
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
