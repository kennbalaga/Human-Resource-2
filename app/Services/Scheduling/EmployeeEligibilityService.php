<?php

namespace App\Services\Scheduling;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Shift;
use App\Services\ScheduleService;
use Illuminate\Validation\ValidationException;

class EmployeeEligibilityService
{
    public function __construct(private readonly ScheduleService $scheduleService) {}

    /**
     * @return array{eligible: array<int, array<string, mixed>>, ineligible: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public function analyze(Department $department, Position $position, Shift $shift, string $workDate): array
    {
        $this->validateTarget($department, $position, $shift);

        $employees = Employee::query()
            ->with(['department', 'position'])
            ->where('department_id', $department->id)
            // Left the organisation, or filed away by HR: not candidates, and
            // not names to read either. Everyone else in the department is
            // still evaluated and still listed with the reason they were
            // passed over — being on leave this week is a fact the person
            // building the roster needs, while a colleague who resigned in
            // March is noise on every screen they appear on.
            ->notArchived()
            ->where('employment_status', '!=', 'terminated')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $evaluations = $employees->map(
            fn (Employee $employee) => $this->evaluateCandidate($employee, $department, $position, $shift, $workDate),
        );

        return [
            'eligible' => $evaluations->where('eligible', true)->values()->all(),
            'ineligible' => $evaluations->where('eligible', false)->values()->all(),
            'warnings' => [
                'Skills, certifications, and declared availability are not yet available and were not evaluated. Declared shift preference is used as a soft ranking signal below, not a hard eligibility filter.',
                'HR review is required before using any result in the manual scheduling form.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function evaluateCandidate(Employee $employee, Department $department, Position $position, Shift $shift, string $workDate): array
    {
        $employee->loadMissing(['department', 'position']);
        $reasons = collect();

        if ($employee->employment_status !== 'active') {
            $reasons->push(['code' => 'employee_inactive', 'message' => 'Employee is not active.']);
        }

        // Separate from the status check above, and not covered by it: a record
        // can be archived while its employment status still reads active, and
        // the roster board draws its candidates from the whole department
        // rather than from a filtered picker. Without this, the one employee
        // HR has deliberately filed away is the one the board can still roster.
        if ($employee->isArchived()) {
            $reasons->push(['code' => 'employee_archived', 'message' => 'Employee record is archived.']);
        }

        if (! $employee->department?->is_active) {
            $reasons->push(['code' => 'employee_department_inactive', 'message' => 'Employee department is inactive.']);
        }

        if (! $employee->position?->is_active) {
            $reasons->push(['code' => 'employee_position_inactive', 'message' => 'Employee position is inactive.']);
        }

        if ($employee->department_id !== $department->id) {
            $reasons->push(['code' => 'department_mismatch', 'message' => 'Employee belongs to a different department.']);
        }

        if ($employee->position_id !== $position->id) {
            $reasons->push(['code' => 'position_mismatch', 'message' => 'Employee holds a different position.']);
        }

        if ($this->hasApprovedLeave($employee, $shift, $workDate)) {
            $reasons->push(['code' => 'approved_leave', 'message' => 'Employee has approved leave covering this shift.']);
        }

        $conflicts = $this->scheduleService->conflictsFor($employee, $shift, $workDate);
        if ($conflicts->isNotEmpty()) {
            $reasons->push([
                'code' => 'schedule_overlap',
                'message' => 'Employee has an overlapping scheduled assignment.',
                'conflict_ids' => $conflicts->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ]);
        }
        $restConflicts = $this->scheduleService->restConflictsFor($employee, $shift, $workDate);
        if ($restConflicts->isNotEmpty()) {
            $reasons->push([
                'code' => 'insufficient_rest',
                'message' => 'Employee would not receive the configured minimum rest period.',
                'conflict_ids' => $restConflicts->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ]);
        }

        return [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'name' => $employee->full_name,
            'eligible' => $reasons->isEmpty(),
            'reasons' => $reasons->values()->all(),
        ];
    }

    private function hasApprovedLeave(Employee $employee, Shift $shift, string $workDate): bool
    {
        [$start, $end] = $this->scheduleService->intervalFor($shift, $workDate);

        return LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();
    }

    private function validateTarget(Department $department, Position $position, Shift $shift): void
    {
        $errors = collect();

        if (! $department->is_active) {
            $errors->put('department_id', 'The selected department is inactive.');
        }
        if (! $position->is_active) {
            $errors->put('position_id', 'The selected position is inactive.');
        }
        if ($position->department_id !== $department->id) {
            $errors->put('position_id', 'The selected position does not belong to the selected department.');
        }
        if (! $shift->is_active) {
            $errors->put('shift_id', 'The selected shift is inactive.');
        }

        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->all());
        }
    }
}
