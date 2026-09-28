<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShiftSwap\StoreShiftSwapRequest;
use App\Models\Employee;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Notifications\PreferenceMailNotification;
use App\Services\PreferenceNotificationService;
use App\Services\ShiftSwapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ShiftSwapController extends Controller
{
    use ScopesWorkforceAccess;

    /**
     * Shift swaps live inside the Preferences page now. This route stays only
     * so old links and email notifications still land somewhere — the access
     * check moved with the page, but still runs here first, so a device with
     * no business seeing swaps gets turned away rather than redirected into a
     * page it happens to have partial access to anyway.
     */
    public function index(Request $request): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_if($employee === null, 403);
        $canManage = Gate::forUser($request->user())->allows('workforce.manage');

        // Shift swaps exist to cover a clinical shift, not an office desk.
        // A manager keeps access to review requests for the units they run;
        // anyone else needs to be clinical staff, or
        // there is nothing here for them to see at all.
        abort_unless($canManage || $employee->canUseShiftSwaps(), 403);

        return redirect(route('schedule-preferences.index').'#shift-swaps');
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

        // The moment the request becomes a manager's to answer is the moment
        // somebody has to be told. Until now nobody was: acceptance notified the
        // requester that it had gone to their manager, and the manager found out
        // only by opening a page they had no reason to open.
        if ($swap->status === 'pending_manager') {
            $this->notifyReviewers($swap, $notifications);
        }

        return back()->with('success', "Swap request {$status}.");
    }

    public function approve(
        Request $request,
        ShiftSwapRequest $shiftSwapRequest,
        ShiftSwapService $service,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        abort_unless(Gate::forUser($request->user())->allows('workforce.manage'), 403);
        $this->requireSupervisionOfBoth($request, $shiftSwapRequest);
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
        $this->requireSupervisionOfBoth($request, $shiftSwapRequest);
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
