<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PreferredDayOff;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\User;
use App\Services\Scheduling\ScheduleLockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PreferredDayOffService
{
    public function __construct(private readonly ScheduleLockService $scheduleLockService) {}

    /** @param array{preferred_date: string, reason?: string|null} $data */
    public function create(Employee $employee, array $data): PreferredDayOff
    {
        return DB::transaction(function () use ($employee, $data) {
            $date = Carbon::parse($data['preferred_date'], config('schedule.timezone'))->startOfDay();

            if ($date->toDateString() < now(config('schedule.timezone'))->toDateString()) {
                throw ValidationException::withMessages(['preferred_date' => 'Choose a future date.']);
            }

            $openExists = PreferredDayOff::query()
                ->where('employee_id', $employee->id)
                ->whereDate('preferred_date', $date->toDateString())
                ->whereIn('status', ['pending', 'approved'])
                ->exists();
            if ($openExists) {
                throw ValidationException::withMessages(['preferred_date' => 'You already have an open request for this date.']);
            }

            $onApprovedLeave = LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $date->toDateString())
                ->whereDate('end_date', '>=', $date->toDateString())
                ->exists();
            if ($onApprovedLeave) {
                throw ValidationException::withMessages(['preferred_date' => 'You already have approved leave covering this date.']);
            }

            return PreferredDayOff::query()->create([
                'uuid' => (string) Str::uuid(),
                'employee_id' => $employee->id,
                'preferred_date' => $date->toDateString(),
                'reason' => $data['reason'] ?? null,
                'status' => 'pending',
            ]);
        });
    }

    public function approve(PreferredDayOff $request, User $reviewer, ?string $notes): PreferredDayOff
    {
        return DB::transaction(function () use ($request, $reviewer, $notes) {
            $request = PreferredDayOff::query()->lockForUpdate()->with('employee.department')->findOrFail($request->id);
            $this->ensurePending($request);

            if ($request->employee->department !== null) {
                $this->scheduleLockService->assertUnlocked($request->employee->department, $request->preferred_date);
            }

            $hasAssignment = ScheduleAssignment::query()
                ->where('employee_id', $request->employee_id)
                ->where('status', 'scheduled')
                ->whereDate('work_date', $request->preferred_date->toDateString())
                ->exists();
            if ($hasAssignment) {
                throw ValidationException::withMessages(['preferred_date' => 'This employee already has a scheduled shift that day. Remove it before approving the day off.']);
            }

            ScheduleDayOff::query()->firstOrCreate(
                ['employee_id' => $request->employee_id, 'work_date' => $request->preferred_date->toDateString()],
                ['source' => 'preference', 'notes' => 'Approved preferred day-off request.', 'created_by' => $reviewer->id],
            );

            $request->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'reviewer_notes' => $notes,
            ]);

            return $request->refresh();
        });
    }

    public function reject(PreferredDayOff $request, User $reviewer, string $notes): PreferredDayOff
    {
        return DB::transaction(function () use ($request, $reviewer, $notes) {
            $request = PreferredDayOff::query()->lockForUpdate()->findOrFail($request->id);
            $this->ensurePending($request);

            $request->update([
                'status' => 'rejected',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'reviewer_notes' => $notes,
            ]);

            return $request->refresh();
        });
    }

    public function cancel(PreferredDayOff $request, User $user): PreferredDayOff
    {
        if ($request->employee_id !== $user->employee?->id
            && ! Gate::forUser($user)->allows('workforce.manage')) {
            abort(403);
        }

        return DB::transaction(function () use ($request) {
            $request = PreferredDayOff::query()->lockForUpdate()->findOrFail($request->id);
            if (! in_array($request->status, ['pending', 'approved'], true)) {
                throw ValidationException::withMessages(['preferred_date' => 'Only pending or approved requests can be cancelled.']);
            }

            $request->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return $request->refresh();
        });
    }

    private function ensurePending(PreferredDayOff $request): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['preferred_date' => 'Only pending requests can be reviewed.']);
        }
    }
}
