<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShiftSwap\StoreShiftSwapRequest;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\ShiftSwapRequest;
use App\Notifications\PreferenceMailNotification;
use App\Services\PreferenceNotificationService;
use App\Services\ShiftSwapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ShiftSwapController extends Controller
{
    use ScopesWorkforceAccess;

    public function index(Request $request): View
    {
        $employee = $request->user()->employee;
        abort_if($employee === null, 403);
        $canManage = Gate::forUser($request->user())->allows('workforce.manage');

        // Shift swaps exist to cover a clinical shift, not an office desk.
        // A manager keeps access to review requests for the units they run;
        // anyone else needs to be clinical staff, or
        // there is nothing here for them to see at all.
        abort_unless($canManage || $employee->canUseShiftSwaps(), 403);

        $today = now(config('schedule.timezone'))->toDateString();
        $horizon = now(config('schedule.timezone'))->addDays(45)->toDateString();

        $baseQuery = ShiftSwapRequest::query()
            ->with(['requesterEmployee.department', 'targetEmployee.department', 'requesterAssignment.shift', 'targetAssignment.shift', 'reviewer']);

        if (! $canManage) {
            $baseQuery->where(function ($builder) use ($employee) {
                $builder->where('requester_employee_id', $employee->id)->orWhere('target_employee_id', $employee->id);
            });
        } else {
            // A swap is between two people. A reviewer sees it when the side
            // that raised it is theirs to supervise -- the two are in the same
            // department by construction, since the picker only ever offers
            // colleagues from the requester's own unit.
            Employee::constrainRelatedQuery($baseQuery, $request->user(), 'requesterEmployee');
        }

        $requests = (clone $baseQuery)->latest()->paginate(15)->withQueryString();

        $summary = [
            'pending_sent' => (clone $baseQuery)->where('requester_employee_id', $employee->id)->whereIn('status', ['pending_target', 'pending_manager'])->count(),
            'awaiting_me' => (clone $baseQuery)->where('target_employee_id', $employee->id)->where('status', 'pending_target')->count(),
            'awaiting_manager' => (clone $baseQuery)->where('status', 'pending_manager')->count(),
            'approved' => (clone $baseQuery)->where('status', 'approved')->count(),
        ];

        // A manager keeps the page to review every request, but "Request swap"
        // is personal self-service — offering it to someone with no rotating
        // shift to trade would just fail validation the moment they submitted.
        $canRequestSwap = $employee->canUseShiftSwaps();

        return view('shift-swaps.index', [
            'requests' => $requests,
            'summary' => $summary,
            'employee' => $employee,
            'canManage' => $canManage,
            'canManageData' => $canManage && $request->user()->canManageData(),
            'canRequestSwap' => $canRequestSwap,
            'myAssignments' => $canRequestSwap
                ? ScheduleAssignment::query()
                    ->with('shift')
                    ->where('employee_id', $employee->id)
                    ->where('status', 'scheduled')
                    ->whereBetween('work_date', [$today, $horizon])
                    ->orderBy('work_date')
                    ->limit(100)
                    ->get()
                : collect(),
            'colleagueAssignments' => $canRequestSwap
                ? ScheduleAssignment::query()
                    ->with(['shift', 'employee'])
                    ->whereHas('employee', fn ($q) => $q->where('department_id', $employee->department_id)->where('id', '!=', $employee->id))
                    ->where('status', 'scheduled')
                    ->whereBetween('work_date', [$today, $horizon])
                    ->orderBy('work_date')
                    ->limit(200)
                    ->get()
                : collect(),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }

    public function store(
        StoreShiftSwapRequest $request,
        ShiftSwapService $service,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $swap = $service->create($request->user()->employee, $request->validated());
        $this->notify($swap->targetEmployee, 'A shift swap was requested', [
            $swap->requesterEmployee->full_name.' asked to swap shifts with you.',
            'Review it from your Shift Swaps page.',
        ], $notifications);

        return back()->with('success', 'Swap request sent to your colleague.');
    }

    public function respond(
        Request $request,
        ShiftSwapRequest $shiftSwapRequest,
        ShiftSwapService $service,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $employee = $request->user()->employee;
        abort_if($employee === null, 403);
        $validated = $request->validate([
            'accept' => ['required', 'boolean'],
            'target_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $swap = $service->respond($shiftSwapRequest, $employee, (bool) $validated['accept'], $validated['target_notes'] ?? null);
        $status = $swap->status === 'pending_manager' ? 'accepted' : 'declined';
        $this->notify($swap->requesterEmployee, "Your swap request was {$status}", [
            $swap->targetEmployee->full_name." {$status} your swap request.",
            $status === 'accepted' ? 'It now needs manager approval.' : 'You may send a new request to someone else.',
        ], $notifications);

        return back()->with('success', "Swap request {$status}.");
    }

    public function approve(
        Request $request,
        ShiftSwapRequest $shiftSwapRequest,
        ShiftSwapService $service,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        abort_unless(Gate::forUser($request->user())->allows('workforce.manage'), 403);
        $this->requireSupervision($request, $shiftSwapRequest->loadMissing('requesterEmployee')->requesterEmployee, 'workforce.manage.record');
        $validated = $request->validate(['reviewer_notes' => ['nullable', 'string', 'max:500']]);
        $swap = $service->approve($shiftSwapRequest, $request->user(), $validated['reviewer_notes'] ?? null);

        foreach ([$swap->requesterEmployee, $swap->targetEmployee] as $employee) {
            $this->notify($employee, 'Shift swap approved', [
                'Your shift swap with '.($employee->id === $swap->requester_employee_id ? $swap->targetEmployee->full_name : $swap->requesterEmployee->full_name).' was approved.',
                'Check your schedule for the updated shift.',
            ], $notifications);
        }

        return back()->with('success', 'Swap approved. Both schedules were updated.');
    }

    public function reject(
        Request $request,
        ShiftSwapRequest $shiftSwapRequest,
        ShiftSwapService $service,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        abort_unless(Gate::forUser($request->user())->allows('workforce.manage'), 403);
        $this->requireSupervision($request, $shiftSwapRequest->loadMissing('requesterEmployee')->requesterEmployee, 'workforce.manage.record');
        $validated = $request->validate(['reviewer_notes' => ['required', 'string', 'min:5', 'max:500']]);
        $swap = $service->reject($shiftSwapRequest, $request->user(), $validated['reviewer_notes']);

        foreach ([$swap->requesterEmployee, $swap->targetEmployee] as $employee) {
            $this->notify($employee, 'Shift swap rejected', [
                'Your shift swap request was rejected.',
                'Reviewer note: '.$swap->reviewer_notes,
            ], $notifications);
        }

        return back()->with('success', 'Swap request rejected.');
    }

    public function cancel(
        Request $request,
        ShiftSwapRequest $shiftSwapRequest,
        ShiftSwapService $service,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $swap = $service->cancel($shiftSwapRequest, $request->user());
        $other = $request->user()->employee?->id === $swap->requester_employee_id ? $swap->targetEmployee : $swap->requesterEmployee;
        $this->notify($other, 'Shift swap cancelled', [
            'A shift swap request involving you was cancelled.',
        ], $notifications);

        return back()->with('success', 'Swap request cancelled.');
    }

    /** @param array<int, string> $lines */
    private function notify(Employee $employee, string $subject, array $lines, PreferenceNotificationService $notifications): void
    {
        $employee->loadMissing('user.preference');
        $user = $employee->user;

        if ($user === null) {
            return;
        }

        $notifications->send($user, 'schedule_updates', new PreferenceMailNotification(
            $subject,
            $lines,
            'View Shift Swaps',
            route('shift-swaps.index'),
        ));
    }
}
