<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesWorkforce;
use App\Http\Controllers\Controller;
use App\Http\Resources\TimesheetResource;
use App\Models\Timesheet;
use App\Services\TimesheetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TimesheetController extends Controller
{
    use AuthorizesWorkforce;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requireRead($request->user());
        $validated = $request->validate([
            'status' => ['nullable', 'in:draft,submitted,approved,rejected'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $manager = $this->canManage($request->user());
        $records = Timesheet::query()->with(['employee.user', 'employee.department', 'employee.position', 'entries'])
            ->when($manager, fn (Builder $query) => $this->constrainToSupervised($query, $request->user()))
            ->when(! $manager, fn (Builder $query) => $query->where('employee_id', $request->user()->employee?->id))
            ->when($manager && ! empty($validated['employee_id']), fn (Builder $query) => $query->where('employee_id', $validated['employee_id']))
            ->when($validated['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->latest('period_start')->paginate($validated['per_page'] ?? 25);

        return TimesheetResource::collection($records);
    }

    public function show(Request $request, Timesheet $timesheet): TimesheetResource
    {
        $this->requireReadFor($request->user(), $timesheet->loadMissing('employee')->employee);

        return new TimesheetResource($timesheet->load(['employee.user', 'employee.department', 'employee.position', 'entries']));
    }

    public function submit(Request $request, Timesheet $timesheet, TimesheetService $service): TimesheetResource
    {
        abort_unless($request->user()->tokenCan('timesheet:write') || $request->user()->tokenCan('workforce:write'), 403);
        $timesheet = $service->submit($timesheet, $request->user());

        return new TimesheetResource($timesheet->load(['employee.user', 'employee.department', 'employee.position', 'entries']));
    }

    public function approve(Request $request, Timesheet $timesheet, TimesheetService $service): TimesheetResource
    {
        $this->requireManagerFor($request->user(), $timesheet->loadMissing('employee')->employee);
        $validated = $request->validate(['reviewer_notes' => ['nullable', 'string', 'max:500']]);
        $timesheet = $service->review($timesheet, $request->user(), 'approved', $validated['reviewer_notes'] ?? null);

        return new TimesheetResource($timesheet->load(['employee.user', 'employee.department', 'employee.position', 'entries']));
    }

    public function reject(Request $request, Timesheet $timesheet, TimesheetService $service): TimesheetResource
    {
        $this->requireManagerFor($request->user(), $timesheet->loadMissing('employee')->employee);
        $validated = $request->validate(['reviewer_notes' => ['required', 'string', 'min:5', 'max:500']]);
        $timesheet = $service->review($timesheet, $request->user(), 'rejected', $validated['reviewer_notes']);

        return new TimesheetResource($timesheet->load(['employee.user', 'employee.department', 'employee.position', 'entries']));
    }
}
