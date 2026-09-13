<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesWorkforce;
use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Leave\LeaveAttachmentStorage;
use App\Services\LeaveService;
use App\Services\Security\AttachmentMalwareScanner;
use App\Services\Security\UnsafeAttachmentException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    use AuthorizesWorkforce;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->requireRead($request->user());
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(LeaveRequest::FILTERABLE_STATUSES)],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $manager = $this->canManage($request->user());
        $records = LeaveRequest::query()->with(['employee.user', 'employee.department', 'employee.position', 'leaveType', 'attachments'])
            ->when($manager, fn (Builder $query) => $this->constrainToSupervised($query, $request->user()))
            ->when(! $manager, fn (Builder $query) => $query->where('employee_id', $request->user()->employee?->id))
            ->when($manager && ! empty($validated['employee_id']), fn (Builder $query) => $query->where('employee_id', $validated['employee_id']))
            ->when($validated['status'] ?? null, fn (Builder $query, $status) => $query->whereLifecycleStatus($status))
            ->latest()->paginate($validated['per_page'] ?? 25);

        return LeaveRequestResource::collection($records);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->requireReadFor($request->user(), $leaveRequest->loadMissing('employee')->employee);

        return new LeaveRequestResource($leaveRequest->load(['employee.user', 'employee.department', 'employee.position', 'leaveType', 'attachments']));
    }

    public function store(
        StoreLeaveRequest $request,
        LeaveService $service,
        AttachmentMalwareScanner $scanner,
        LeaveAttachmentStorage $attachments,
    ): LeaveRequestResource {
        abort_unless($request->user()->tokenCan('leave:write') || $request->user()->tokenCan('workforce:write'), 403);
        $data = $request->validated();
        $type = LeaveType::query()->findOrFail($data['leave_type_id']);
        if ($type->requires_attachment && ! $request->hasFile('attachments')) {
            throw ValidationException::withMessages(['attachments' => ["{$type->name} requires a supporting attachment."]]);
        }

        foreach ($request->file('attachments', []) as $file) {
            try {
                $scanner->scan($file);
            } catch (UnsafeAttachmentException $exception) {
                throw ValidationException::withMessages(['attachments' => [$exception->userMessage]]);
            }
        }

        $leave = $service->create($request->user()->employee, $data);
        foreach ($request->file('attachments', []) as $file) {
            $attachments->store($file, $leave, $request->user());
        }

        return new LeaveRequestResource($leave->load(['employee.user', 'employee.department', 'employee.position', 'leaveType', 'attachments']));
    }

    public function approve(Request $request, LeaveRequest $leaveRequest, LeaveService $service): LeaveRequestResource
    {
        $this->requireManagerFor($request->user(), $leaveRequest->loadMissing('employee')->employee);
        $validated = $request->validate(['reviewer_notes' => ['nullable', 'string', 'max:500']]);
        $leave = $service->approve($leaveRequest, $request->user(), $validated['reviewer_notes'] ?? null);

        return new LeaveRequestResource($leave->load(['employee.user', 'employee.department', 'employee.position', 'leaveType', 'attachments']));
    }

    public function reject(Request $request, LeaveRequest $leaveRequest, LeaveService $service): LeaveRequestResource
    {
        $this->requireManagerFor($request->user(), $leaveRequest->loadMissing('employee')->employee);
        $validated = $request->validate(['reviewer_notes' => ['required', 'string', 'min:5', 'max:500']]);
        $leave = $service->reject($leaveRequest, $request->user(), $validated['reviewer_notes']);

        return new LeaveRequestResource($leave->load(['employee.user', 'employee.department', 'employee.position', 'leaveType', 'attachments']));
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest, LeaveService $service): LeaveRequestResource
    {
        abort_unless($request->user()->tokenCan('leave:write') || $request->user()->tokenCan('workforce:write'), 403);
        $leave = $service->cancel($leaveRequest, $request->user());

        return new LeaveRequestResource($leave->load(['employee.user', 'employee.department', 'employee.position', 'leaveType', 'attachments']));
    }
}
