<?php

namespace App\Services\Scheduling;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\ScheduleAssignment;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Resolves an attendance punch to a published ScheduleAssignment. Pure and
 * side-effect free: it reads, classifies, and returns — only
 * AttendanceService::checkIn() writes anything.
 */
class ShiftResolver
{
    public function __construct(private readonly ScheduleService $scheduleService) {}

    public function forPunch(
        Employee $employee,
        CarbonInterface $at,
        int $earlyWindowMinutes,
        int $graceMinutes,
        int $lateBindMinutes,
    ): ShiftResolution {
        $at = Carbon::instance($at);

        $windowMatched = $this->windowMatchedCandidates($employee, $at, $earlyWindowMinutes, $lateBindMinutes);

        if ($windowMatched->isEmpty()) {
            return new ShiftResolution(null, ShiftResolution::UNSCHEDULED, null, ShiftResolution::REASON_NO_CANDIDATES);
        }

        $afterLeave = $windowMatched->reject(
            fn (array $candidate): bool => $this->hasApprovedLeave($employee, $candidate['assignment']->work_date),
        );

        if ($afterLeave->isEmpty()) {
            return new ShiftResolution(null, ShiftResolution::UNSCHEDULED, null, ShiftResolution::REASON_LEAVE_CONFLICT);
        }

        $afterClosed = $afterLeave->reject(
            fn (array $candidate): bool => $this->isBoundToClosedRecord($candidate['assignment']),
        );

        if ($afterClosed->isEmpty()) {
            return new ShiftResolution(null, ShiftResolution::UNSCHEDULED, null, ShiftResolution::REASON_NO_CANDIDATES);
        }

        $chosen = $afterClosed
            ->sortBy([
                fn (array $candidate): int => abs($candidate['start']->diffInSeconds($at)),
                fn (array $candidate): int => $candidate['start']->getTimestamp(),
            ])
            ->first();

        return new ShiftResolution(
            $chosen['assignment'],
            $this->classify($at, $chosen['start'], $earlyWindowMinutes, $graceMinutes, $lateBindMinutes),
            [$chosen['start'], $chosen['end']],
        );
    }

    /**
     * @return Collection<int, array{assignment: ScheduleAssignment, start: Carbon, end: Carbon}>
     */
    private function windowMatchedCandidates(
        Employee $employee,
        Carbon $at,
        int $earlyWindowMinutes,
        int $lateBindMinutes,
    ): Collection {
        return ScheduleAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [
                $at->copy()->subDay()->toDateString(),
                $at->copy()->addDay()->toDateString(),
            ])
            ->with('shift')
            ->get()
            ->map(function (ScheduleAssignment $assignment) {
                [$start, $end] = $this->scheduleService->intervalFor($assignment->shift, $assignment->work_date->toDateString());

                return ['assignment' => $assignment, 'start' => $start, 'end' => $end];
            })
            ->filter(fn (array $candidate): bool => $at->between(
                $candidate['start']->copy()->subMinutes($earlyWindowMinutes),
                $candidate['start']->copy()->addMinutes($lateBindMinutes),
            ))
            ->values();
    }

    private function hasApprovedLeave(Employee $employee, Carbon $workDate): bool
    {
        return LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $workDate->toDateString())
            ->whereDate('end_date', '>=', $workDate->toDateString())
            ->exists();
    }

    private function isBoundToClosedRecord(ScheduleAssignment $assignment): bool
    {
        return AttendanceRecord::query()
            ->where('schedule_assignment_id', $assignment->id)
            ->whereNotNull('check_out_at')
            ->exists();
    }

    private function classify(Carbon $at, Carbon $start, int $earlyWindowMinutes, int $graceMinutes, int $lateBindMinutes): string
    {
        if ($at->lessThan($start->copy()->subMinutes($earlyWindowMinutes))) {
            return ShiftResolution::OFF_SHIFT;
        }

        if ($at->lessThan($start)) {
            return ShiftResolution::EARLY;
        }

        if ($at->lessThanOrEqualTo($start->copy()->addMinutes($graceMinutes))) {
            return ShiftResolution::ON_SHIFT;
        }

        if ($at->lessThanOrEqualTo($start->copy()->addMinutes($lateBindMinutes))) {
            return ShiftResolution::LATE;
        }

        return ShiftResolution::OFF_SHIFT;
    }
}
