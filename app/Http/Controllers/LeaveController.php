<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
use App\Http\Requests\Leave\LeaveFilterRequest;
use App\Http\Requests\Leave\StoreLeaveRequest;
use App\Http\Requests\Leave\StoreLeaveTypeRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Notifications\PreferenceMailNotification;
use App\Services\Leave\LeaveAttachmentStorage;
use App\Services\LeaveService;
use App\Services\PreferenceNotificationService;
use App\Services\ReferenceDataCache;
use App\Services\Security\AttachmentMalwareScanner;
use App\Services\Security\UnsafeAttachmentException;
use App\Support\ScheduleWeek;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LeaveController extends Controller
{
    use ScopesWorkforceAccess;

    public function index(LeaveFilterRequest $request, LeaveService $service, ReferenceDataCache $reference): View
    {
        $filters = $request->validated();
        $employee = $request->user()->employee;
        abort_if($employee === null, 403);
        $canManage = $this->canManage($request);
        // Department and leave type are filled from the reference cache once the
        // rows are in hand: between them they are a few dozen rows the whole app
        // shares, and eager loading each was a round trip of its own.
        $query = LeaveRequest::query()
            ->with(['employee', 'reviewer', 'attachments'])
            ->whereYear('start_date', $filters['year'])
            // A department head holds the same role as HR but runs one unit, so
            // the supervised-department constraint is applied before any filter
            // the request asks for. A department_id in the query string can
            // narrow what they see; it can never widen it.
            ->when($canManage, fn (Builder $builder) => Employee::constrainRelatedQuery($builder, $request->user()))
            ->when(! $canManage, fn (Builder $builder) => $builder->where('employee_id', $employee->id))
            ->when($canManage && ! empty($filters['employee_id']), fn (Builder $builder) => $builder->where('employee_id', $filters['employee_id']))
            ->when($canManage && ! empty($filters['department_id']), fn (Builder $builder) => $builder->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('department_id', $filters['department_id'])))
            ->when($filters['leave_type_id'] ?? null, fn (Builder $builder, $typeId) => $builder->where('leave_type_id', $typeId))
            ->when($filters['status'] ?? null, fn (Builder $builder, $status) => $builder->whereLifecycleStatus($status));

        $types = $reference->leaveTypes()->where('is_active', true)->sortBy('name')->values();

        // Two lists on purpose. The history filter below keeps every type,
        // because a manager filters across the whole workforce and an employee
        // may have older requests against a type they no longer qualify for.
        // The balance cards and the request picker get only what this employee
        // could actually hold -- otherwise every member of staff was shown
        // seven solo parent days they have no ID for.
        $requestableTypes = $service->selectableTypes($employee, $types, now(config('workforce.timezone')));
        $balances = $service->balancesFor($employee, $requestableTypes, (int) $filters['year']);
        $focusDate = ! empty($filters['date']) ? Carbon::parse($filters['date']) : now(config('workforce.timezone'));
        $monthStart = ScheduleWeek::start($focusDate->copy()->startOfMonth());
        $monthEnd = ScheduleWeek::end($focusDate->copy()->endOfMonth());
        $calendarRequests = LeaveRequest::query()
            ->with('employee')
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $monthEnd->toDateString())
            ->whereDate('end_date', '>=', $monthStart->toDateString())
            ->when($canManage, fn (Builder $builder) => Employee::constrainRelatedQuery($builder, $request->user()))
            ->when(! $canManage, fn (Builder $builder) => $builder->where('employee_id', $employee->id))
            ->get();

        $reference->attach($calendarRequests, 'leaveType', 'leave_type_id', LeaveType::class);

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

        // The three headline figures share one filtered query, so they are
        // gathered as subselects in a single round trip rather than three.
        $summaryRow = (array) DB::query()
            ->selectSub((clone $summaryQuery)->where('status', 'pending')->selectRaw('count(*)'), 'pending')
            ->selectSub((clone $summaryQuery)->where('status', 'approved')->selectRaw('coalesce(sum(requested_days), 0)'), 'approved_days')
            ->selectSub((clone $summaryQuery)->whereHas('attachments')->selectRaw('count(*)'), 'attachments')
            ->first();

        $requests = $query->latest()->paginate(15)->withQueryString();

        $reference->attach($requests, 'leaveType', 'leave_type_id', LeaveType::class);
        $reference->attach($requests->pluck('employee')->filter(), 'department', 'department_id', Department::class);

        return view('leaves.index', [
            'requests' => $requests,
            'summary' => [
                'pending' => (int) $summaryRow['pending'],
                'approved_days' => (float) $summaryRow['approved_days'],
                // Only a yearly allowance is credits somebody actually holds.
                // Per-occasion and uncapped types are granted on the occasion,
                // and adding their ceilings in drowned the real figure: two
                // placeholders once made this tile read 890 days.
                'available_days' => $balances->filter(fn ($balance) => $balance->leaveType->isBalanceBacked())->sum->available_days,
                'attachments' => (int) $summaryRow['attachments'],
            ],
            'balances' => $balances,
            'employee' => $employee,
            'types' => $types,
            'requestableTypes' => $requestableTypes,
            'employees' => Employee::query()->visibleTo($request->user())->notArchived()->where('employment_status', 'active')->orderBy('last_name')->get(),
            'departments' => $this->selectableDepartments($request),
            'calendarDays' => $calendarDays,
            'focusDate' => $focusDate,
            'filters' => $filters,
            'canManage' => $canManage,
            'canManageData' => $canManage && $request->user()->canManageData(),
            'canManageLeaveTypes' => $this->canManageLeaveTypes($request) && $request->user()->canManageData(),
            'canRequestLeave' => $this->canRequestLeave($request),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }

    public function store(
        StoreLeaveRequest $request,
        LeaveService $service,
        PreferenceNotificationService $notifications,
        AttachmentMalwareScanner $scanner,
        LeaveAttachmentStorage $attachments,
    ): RedirectResponse {
        $data = $request->validated();
        $type = LeaveType::query()->findOrFail($data['leave_type_id']);

        // Ahead of the attachment demand on purpose: nobody should be asked to
        // upload a medical certificate for an entitlement they cannot claim.
        // LeaveService checks this again inside the transaction, which is what
        // the API path and any future caller rely on.
        $service->assertEligible($request->user()->employee, $type, Carbon::parse($data['start_date'], config('workforce.timezone')));

        if ($type->requires_attachment && ! $request->hasFile('attachments')) {
            throw ValidationException::withMessages(['attachments' => "{$type->name} requires a supporting attachment."]);
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
        $this->notifyEmployee($leave, 'submitted', $notifications);

        return back()->with('success', 'Leave request submitted for approval.');
    }

    public function storeType(StoreLeaveTypeRequest $request): RedirectResponse
    {
        LeaveType::query()->create($request->validated());

        return back()->with('success', 'Leave type created successfully. It is now available for leave requests.');
    }

    public function approve(
        Request $request,
        LeaveRequest $leaveRequest,
        LeaveService $service,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $this->requireManager($request);
        $this->requireSupervision($request, $leaveRequest->loadMissing('employee')->employee, 'workforce.manage.record');
        $validated = $request->validate(['reviewer_notes' => ['nullable', 'string', 'max:500']]);
        $leave = $service->approve($leaveRequest, $request->user(), $validated['reviewer_notes'] ?? null);
        $this->notifyEmployee($leave, 'approved', $notifications);

        return back()->with('success', 'Leave request approved and balance updated.');
    }

    public function reject(
        Request $request,
        LeaveRequest $leaveRequest,
        LeaveService $service,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $this->requireManager($request);
        $this->requireSupervision($request, $leaveRequest->loadMissing('employee')->employee, 'workforce.manage.record');
        $validated = $request->validate(['reviewer_notes' => ['required', 'string', 'min:5', 'max:500']]);
        $leave = $service->reject($leaveRequest, $request->user(), $validated['reviewer_notes']);
        $this->notifyEmployee($leave, 'rejected', $notifications);

        return back()->with('success', 'Leave request rejected.');
    }

    public function cancel(
        Request $request,
        LeaveRequest $leaveRequest,
        LeaveService $service,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $leave = $service->cancel($leaveRequest, $request->user());
        $this->notifyEmployee($leave, 'cancelled', $notifications);

        return back()->with('success', 'Leave request cancelled and balance restored.');
    }

    private function canManage(Request $request): bool
    {
        return Gate::forUser($request->user())->allows('workforce.view');
    }

    private function requireManager(Request $request): void
    {
        abort_unless(Gate::forUser($request->user())->allows('workforce.manage'), 403);
    }

    private function canManageLeaveTypes(Request $request): bool
    {
        return Gate::forUser($request->user())->allows('hr.view');
    }

    private function canRequestLeave(Request $request): bool
    {
        return ! $request->user()->hasAnyRole(StoreLeaveRequest::ROLES_WITHOUT_SELF_SERVICE);
    }

    private function notifyEmployee(
        LeaveRequest $leave,
        string $status,
        PreferenceNotificationService $notifications,
    ): void {
        $leave->loadMissing(['employee.user.preference', 'leaveType']);
        $user = $leave->employee->user;

        if ($user === null) {
            return;
        }

        $subject = match ($status) {
            'submitted' => 'Leave request received',
            'approved' => 'Leave request approved',
            'rejected' => 'Leave request rejected',
            default => 'Leave request cancelled',
        };

        $lines = [
            'Your '.$leave->leaveType->name.' request is now '.$status.'.',
            'Leave period: '.$leave->start_date->format('F j, Y').'–'.$leave->end_date->format('F j, Y').'.',
            'Requested days: '.number_format((float) $leave->requested_days, 1).'.',
        ];

        if (filled($leave->reviewer_notes)) {
            $lines[] = 'Reviewer note: '.$leave->reviewer_notes;
        }

        $notifications->send($user, 'leave_updates', new PreferenceMailNotification(
            $subject,
            $lines,
            'View Leave Requests',
            route('leaves.index'),
        ));
    }
}
