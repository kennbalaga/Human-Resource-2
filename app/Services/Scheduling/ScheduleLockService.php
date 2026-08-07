<?php

namespace App\Services\Scheduling;

use App\Models\Department;
use App\Models\ScheduleLock;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleLockService
{
    public function isLocked(Department $department, Carbon $date): bool
    {
        return $this->activeLockCovering($department, $date) !== null;
    }

    public function assertUnlocked(Department $department, Carbon $date): void
    {
        $lock = $this->activeLockCovering($department, $date);

        if ($lock !== null) {
            throw ValidationException::withMessages([
                'schedule' => "{$department->name} is locked from {$lock->start_date->format('M j, Y')} to {$lock->end_date->format('M j, Y')}. Unlock it before making changes to {$date->format('M j, Y')}.",
            ]);
        }
    }

    public function lock(Department $department, string $startDate, string $endDate, User $user, ?string $notes): ScheduleLock
    {
        return DB::transaction(function () use ($department, $startDate, $endDate, $user, $notes) {
            $start = Carbon::parse($startDate, config('schedule.timezone'))->startOfDay();
            $end = Carbon::parse($endDate, config('schedule.timezone'))->startOfDay();

            if ($end->lessThan($start)) {
                throw ValidationException::withMessages(['end_date' => 'The end date must be on or after the start date.']);
            }

            $overlapping = ScheduleLock::query()
                ->where('department_id', $department->id)
                ->whereNull('unlocked_at')
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())
                ->exists();
            if ($overlapping) {
                throw ValidationException::withMessages(['start_date' => 'Part of this range is already locked.']);
            }

            return ScheduleLock::query()->create([
                'department_id' => $department->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'locked_by' => $user->id,
                'locked_at' => now(),
                'notes' => $notes,
            ]);
        });
    }

    public function unlock(ScheduleLock $lock, User $user): ScheduleLock
    {
        return DB::transaction(function () use ($lock, $user) {
            $lock = ScheduleLock::query()->lockForUpdate()->findOrFail($lock->id);

            if ($lock->unlocked_at !== null) {
                throw ValidationException::withMessages(['schedule' => 'This period is already unlocked.']);
            }

            $lock->update(['unlocked_by' => $user->id, 'unlocked_at' => now()]);

            return $lock->refresh();
        });
    }

    private function activeLockCovering(Department $department, Carbon $date): ?ScheduleLock
    {
        return ScheduleLock::query()
            ->where('department_id', $department->id)
            ->whereNull('unlocked_at')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->first();
    }
}
