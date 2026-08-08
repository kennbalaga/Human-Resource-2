<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\ScheduleLockRequest;
use App\Models\Department;
use App\Models\ScheduleLock;
use App\Services\Scheduling\ScheduleLockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ScheduleLockController extends Controller
{
    public function store(ScheduleLockRequest $request, ScheduleLockService $service): RedirectResponse
    {
        $data = $request->validated();
        $department = Department::query()->findOrFail($data['department_id']);
        $service->lock($department, $data['start_date'], $data['end_date'], $request->user(), $data['notes'] ?? null);

        return back()->with('success', "Schedule locked for {$department->name} from {$data['start_date']} to {$data['end_date']}.");
    }

    public function destroy(Request $request, ScheduleLock $scheduleLock, ScheduleLockService $service): RedirectResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('hr.manage'), 403);
        $service->unlock($scheduleLock, $request->user());

        return back()->with('success', 'Schedule unlocked.');
    }
}
