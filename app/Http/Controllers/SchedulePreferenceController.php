<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
use App\Http\Requests\SchedulePreference\StorePreferredDayOffRequest;
use App\Models\Employee;
use App\Models\PreferredDayOff;
use App\Models\Shift;
use App\Notifications\PreferenceMailNotification;
use App\Services\PreferenceNotificationService;
use App\Services\PreferredDayOffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SchedulePreferenceController extends Controller
{
    use ScopesWorkforceAccess;

    public function index(Request $request): View
    {
        $employee = $request->user()->employee;
        abort_if($employee === null, 403);
        $canManage = Gate::forUser($request->user())->allows('workforce.manage');

        $query = PreferredDayOff::query()->with(['employee.department', 'reviewer']);
        $requests = $canManage
            ? Employee::constrainRelatedQuery($query, $request->user())->latest()->paginate(15)->withQueryString()
            : $query->where('employee_id', $employee->id)->latest()->paginate(15)->withQueryString();

        return view('schedule-preferences.index', [
            'requests' => $requests,
            'employee' => $employee,
            'canManage' => $canManage,
            'canManageData' => $canManage && $request->user()->canManageData(),
            'shifts' => Shift::query()->where('is_active', true)->orderBy('start_time')->get(),
            'weekdays' => [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'],
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }

    public function updateStandingPreference(
        Request $request,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $employee = $request->user()->employee;
        abort_if($employee === null, 403);
        $validated = $request->validate([
            'preferred_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'preferred_weekly_off_day' => ['nullable', 'integer', 'between:1,7'],
        ]);

        $employee->update([
            'preferred_shift_id' => $validated['preferred_shift_id'] ?: null,
            'preferred_weekly_off_day' => $validated['preferred_weekly_off_day'] ?: null,
        ]);

        return back()->with('success', 'Your scheduling preferences were saved.');
    }

    public function storeDayOff(
        StorePreferredDayOffRequest $request,
        PreferredDayOffService $service,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $preference = $service->create($request->user()->employee, $request->validated());
        $this->notify($preference, 'Preferred day-off request received', $notifications);

        return back()->with('success', 'Preferred day-off request submitted for review.');
    }

    public function approveDayOff(
        Request $request,
        PreferredDayOff $preferredDayOff,
        PreferredDayOffService $service,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        abort_unless(Gate::forUser($request->user())->allows('workforce.manage'), 403);
        $this->requireSupervision($request, $preferredDayOff->loadMissing('employee')->employee, 'workforce.manage.record');
        $validated = $request->validate(['reviewer_notes' => ['nullable', 'string', 'max:500']]);
        $preference = $service->approve($preferredDayOff, $request->user(), $validated['reviewer_notes'] ?? null);
        $this->notify($preference, 'Preferred day-off approved', $notifications);

        return back()->with('success', 'Preferred day-off approved and added to the schedule.');
    }

    public function rejectDayOff(
        Request $request,
        PreferredDayOff $preferredDayOff,
        PreferredDayOffService $service,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        abort_unless(Gate::forUser($request->user())->allows('workforce.manage'), 403);
        $this->requireSupervision($request, $preferredDayOff->loadMissing('employee')->employee, 'workforce.manage.record');
        $validated = $request->validate(['reviewer_notes' => ['required', 'string', 'min:5', 'max:500']]);
        $preference = $service->reject($preferredDayOff, $request->user(), $validated['reviewer_notes']);
        $this->notify($preference, 'Preferred day-off declined', $notifications);

        return back()->with('success', 'Preferred day-off request declined.');
    }

    public function cancelDayOff(
        Request $request,
        PreferredDayOff $preferredDayOff,
        PreferredDayOffService $service,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $preference = $service->cancel($preferredDayOff, $request->user());
        $this->notify($preference, 'Preferred day-off cancelled', $notifications);

        return back()->with('success', 'Preferred day-off request cancelled.');
    }

    private function notify(PreferredDayOff $preference, string $subject, PreferenceNotificationService $notifications): void
    {
        $preference->loadMissing('employee.user.preference');
        $user = $preference->employee->user;

        if ($user === null) {
            return;
        }

        $lines = [
            'Your preferred day off for '.$preference->preferred_date->format('F j, Y').' is now '.$preference->status.'.',
        ];
        if (filled($preference->reviewer_notes)) {
            $lines[] = 'Reviewer note: '.$preference->reviewer_notes;
        }

        $notifications->send($user, 'schedule_updates', new PreferenceMailNotification(
            $subject,
            $lines,
            'View Preferences',
            route('schedule-preferences.index'),
        ));
    }
}
