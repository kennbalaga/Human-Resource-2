<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\RotationScheduleRequest;
use App\Notifications\PreferenceMailNotification;
use App\Services\PreferenceNotificationService;
use App\Services\Scheduling\AiSchedulingFeatureSettings;
use App\Services\Scheduling\RotationScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class AiRotationScheduleController extends Controller
{
    public function preview(
        RotationScheduleRequest $request,
        RotationScheduleService $rotations,
        AiSchedulingFeatureSettings $featureSettings,
    ): JsonResponse {
        abort_unless($featureSettings->assistantEnabled(), 404);
        $plan = $rotations->plan($request->validated());

        return response()->json(['data' => [
            'rows' => $plan['rows'],
            'assignment_count' => $plan['assignment_count'],
            'day_off_count' => $plan['day_off_count'],
            'skipped_count' => $plan['skipped_count'],
            'skipped' => $plan['skipped']->take(10)->values(),
            'staffing_gaps' => $plan['staffing_gaps']->take(10)->values(),
            'validation_summary' => $plan['skipped']->countBy('reason'),
            'notice' => $plan['notice'],
        ]]);
    }

    public function store(
        RotationScheduleRequest $request,
        RotationScheduleService $rotations,
        AiSchedulingFeatureSettings $featureSettings,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        abort_unless($featureSettings->assistantEnabled(), 404);
        $data = $request->validated();
        $result = $rotations->create($data, $request->user());
        $this->notifyEmployees($result, $data, $notifications);

        $message = "{$result['assignments']->count()} rotating shift assignments and {$result['day_offs']->count()} day offs created.";
        if ($result['skipped_count'] > 0) {
            $message .= " {$result['skipped_count']} conflicts were skipped.";
        }

        return back()->with('success', $message);
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $data */
    private function notifyEmployees(array $result, array $data, PreferenceNotificationService $notifications): void
    {
        $assignmentsByEmployee = $result['assignments']->groupBy('employee_id');
        $dayOffsByEmployee = $result['day_offs']->groupBy('employee_id');

        foreach ($assignmentsByEmployee as $employeeId => $assignments) {
            $employee = $assignments->first()->employee()->with('user.preference')->first();
            if (! $employee?->user) {
                continue;
            }

            $notifications->send($employee->user, 'schedule_updates', new PreferenceMailNotification(
                'New rotating work schedule assigned',
                [
                    'Your rotating schedule has been assigned for '.$data['start_date'].' through '.$data['end_date'].'.',
                    $assignments->count().' work assignments and '.$dayOffsByEmployee->get($employeeId, collect())->count().' day off record(s) were created.',
                    'Please review the schedule calendar for the complete details.',
                ],
                'View My Schedule',
                route('schedules.index', ['date' => $data['start_date']]),
            ));
        }
    }
}
