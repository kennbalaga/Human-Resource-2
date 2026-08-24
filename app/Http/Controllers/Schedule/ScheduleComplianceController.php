<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\ScheduleComplianceRequest;
use App\Models\Department;
use App\Services\Scheduling\ScheduleComplianceService;
use Illuminate\Http\RedirectResponse;

class ScheduleComplianceController extends Controller
{
    public function store(ScheduleComplianceRequest $request, ScheduleComplianceService $service): RedirectResponse
    {
        $data = $request->validated();
        $department = Department::query()->findOrFail($data['department_id']);

        $review = $service->review($department, $data['start_date'], $data['end_date']);

        $message = match ($review->status) {
            'passed' => "Compliance check passed for {$department->name}. No issues found.",
            'passed_with_warnings' => 'Compliance check passed with '.count($review->findings)." staffing warning(s) for {$department->name}.",
            default => 'Compliance check failed for '.$department->name.': '.count($review->findings).' issue(s) found. Review before locking this period.',
        };

        return back()->with('success', $message);
    }
}
