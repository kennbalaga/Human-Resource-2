<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesWorkforce;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\ScheduleAssignmentRequest;
use App\Http\Resources\ScheduleAssignmentResource;
use App\Models\ScheduleAssignment;
use App\Services\ScheduleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ScheduleController extends Controller
{
    use AuthorizesWorkforce;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requireRead($request->user());
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $manager = $this->canManage($request->user());
        $records = ScheduleAssignment::query()->with(['employee.user', 'employee.department', 'employee.position', 'shift'])
            ->when(! $manager, fn (Builder $query) => $query->where('employee_id', $request->user()->employee?->id))
            ->when($manager && ! empty($validated['employee_id']), fn (Builder $query) => $query->where('employee_id', $validated['employee_id']))
            ->when($validated['date_from'] ?? null, fn (Builder $query, $date) => $query->whereDate('work_date', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $query, $date) => $query->whereDate('work_date', '<=', $date))
            ->orderBy('work_date')->paginate($validated['per_page'] ?? 25);

        return ScheduleAssignmentResource::collection($records);
    }

    public function store(ScheduleAssignmentRequest $request, ScheduleService $service): JsonResponse
    {
        $this->requireManager($request->user());
        $assignment = $service->createAssignment($request->validated(), $request->user());

        return (new ScheduleAssignmentResource($assignment->load(['employee.user', 'employee.department', 'employee.position', 'shift'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(ScheduleAssignmentRequest $request, ScheduleAssignment $scheduleAssignment, ScheduleService $service): ScheduleAssignmentResource
    {
        $this->requireManager($request->user());
        $assignment = $service->updateAssignment($scheduleAssignment, $request->validated());

        return new ScheduleAssignmentResource($assignment->load(['employee.user', 'employee.department', 'employee.position', 'shift']));
    }

    public function destroy(Request $request, ScheduleAssignment $scheduleAssignment): Response
    {
        $this->requireManager($request->user());
        $scheduleAssignment->delete();

        return response()->noContent();
    }
}
