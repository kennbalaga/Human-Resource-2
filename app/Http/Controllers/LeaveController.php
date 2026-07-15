<?php

namespace App\Http\Controllers;

use App\Http\Requests\Leave\LeaveFilterRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveAttachment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Integrations\SafeIntegrationDispatcher;
use App\Services\LeaveService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LeaveController extends Controller
{
    public function index(LeaveFilterRequest $request, LeaveService $service): View
    {
        $filters = $request->validated();
        $employee = $request->user()->employee;
        abort_if($employee === null, 403);
        $canManage = $this->canManage($request);
        $query = LeaveRequest::query()
            ->with(['employee.department', 'leaveType', 'reviewer', 'attachments'])
            ->whereYear('start_date', $filters['year'])
            ->when(! $canManage, fn (Builder $builder) => $builder->where('employee_id', $employee->id))
            ->when($canManage && ! empty($filters['employee_id']), fn (Builder $builder) => $builder->where('employee_id', $filters['employee_id']))
            ->when($canManage && ! empty($filters['department_id']), fn (Builder $builder) => $builder->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('department_id', $filters['department_id'])))
            ->when($filters['leave_type_id'] ?? null, fn (Builder $builder, $typeId) => $builder->where('leave_type_id', $typeId))
            ->when($filters['status'] ?? null, fn (Builder $builder, $status) => $builder->where('status', $status));

        $types = LeaveType::query()->where('is_active', true)->orderBy('name')->get();
        $balances = $types->map(fn (LeaveType $type) => $service->balanceFor($employee, $type, (int) $filters['year']))->load('leaveType');
        $focusDate = ! empty($filters['date']) ? Carbon::parse($filters['date']) : now(config('workforce.timezone'));
        $monthStart = $focusDate->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $monthEnd = $focusDate->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $calendarRequests = LeaveRequest::query()
            ->with(['employee', 'leaveType'])
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $monthEnd->toDateString())
            ->whereDate('end_date', '>=', $monthStart->toDateString())
            ->when(! $canManage, fn (Builder $builder) => $builder->where('employee_id', $employee->id))
            ->get();

        $calendarDays = collect(CarbonPeriod::create($monthStart, $monthEnd))->map(function ($date) use ($calendarRequests, $focusDate) {
            $day = Carbon::instance($date);

            return [
                'date' => $day,
                'current_month' => $day->month === $focusDate->month,
                'today' => $day->isToday(),
                'requests' => $calendarRequests->filter(fn (LeaveRequest $leave) => $day->betweenIncluded($leave->start_date, $leave->end_date) && ! $day->isWeekend())->values(),
            ];
        });

        $summaryQuery = clone $query;

        return view('leaves.index', [
            'requests' => $query->latest()->paginate(15)->withQueryString(),
            'summary' => [
                'pending' => (clone $summaryQuery)->where('status', 'pending')->count(),
                'approved_days' => (float) (clone $summaryQuery)->where('status', 'approved')->sum('requested_days'),
                'available_days' => $balances->sum->available_days,
                'attachments' => (clone $summaryQuery)->whereHas('attachments')->count(),
            ],
            'balances' => $balances,
            'types' => $types,
            'employees' => Employee::query()->where('employment_status', 'active')->orderBy('last_name')->get(),
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(),
            'calendarDays' => $calendarDays,
            'focusDate' => $focusDate,
            'filters' => $filters,
            'canManage' => $canManage,
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
            'notifications' => collect(),
        ]);
    }

    public function store(StoreLeaveRequest $request, LeaveService $service): RedirectResponse
    {
        $data = $request->validated();
        $type = LeaveType::query()->findOrFail($data['leave_type_id']);
        if ($type->requires_attachment && ! $request->hasFile('attachments')) {
            throw ValidationException::withMessages(['attachments' => "{$type->name} requires a supporting attachment."]);
        }

        $leave = $service->create($request->user()->employee, $data);
        foreach ($request->file('attachments', []) as $file) {
            $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs('leave-attachments/'.$leave->uuid, $filename, 'local');
            LeaveAttachment::query()->create([
                'leave_request_id' => $leave->id,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
        }

        return back()->with('success', 'Leave request submitted for approval.');
    }

    public function approve(Request $request, LeaveRequest $leaveRequest, LeaveService $service, SafeIntegrationDispatcher $integrations): RedirectResponse
    {
        $this->requireManager($request);
        $validated = $request->validate(['reviewer_notes' => ['nullable', 'string', 'max:500']]);
        $service->approve($leaveRequest, $request->user(), $validated['reviewer_notes'] ?? null);
        $integrations->zapier('leave.approved', ['leave_request_id' => $leaveRequest->id, 'employee_id' => $leaveRequest->employee_id, 'start_date' => $leaveRequest->start_date->toDateString(), 'end_date' => $leaveRequest->end_date->toDateString(), 'requested_days' => (float) $leaveRequest->requested_days]);

        return back()->with('success', 'Leave request approved and balance updated.');
    }

    public function reject(Request $request, LeaveRequest $leaveRequest, LeaveService $service): RedirectResponse
    {
        $this->requireManager($request);
        $validated = $request->validate(['reviewer_notes' => ['required', 'string', 'min:5', 'max:500']]);
        $service->reject($leaveRequest, $request->user(), $validated['reviewer_notes']);

        return back()->with('success', 'Leave request rejected.');
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest, LeaveService $service): RedirectResponse
    {
        $service->cancel($leaveRequest, $request->user());

        return back()->with('success', 'Leave request cancelled and balance restored.');
    }

    private function canManage(Request $request): bool
    {
        return $request->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])->exists();
    }

    private function requireManager(Request $request): void
    {
        abort_unless($this->canManage($request), 403);
    }
}
