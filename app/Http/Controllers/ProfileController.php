<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdateProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user()->load(['roles', 'employee.department', 'employee.position', 'employee.supervisor.user']);
        $employee = $user->employee;
        $employee?->loadCount(['attendanceRecords', 'scheduleAssignments', 'timesheets', 'leaveRequests']);

        return view('profile.show', [
            'user' => $user,
            'employee' => $employee,
            'currentRole' => $user->roles->pluck('name')->join(', ') ?: 'Employee',
            'notifications' => collect(),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $request->user()->employee->update($request->validated());

        return back()->with('success', 'Your contact information has been updated.');
    }
}
