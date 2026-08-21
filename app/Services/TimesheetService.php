<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimesheetService
{
    public function approveAttendance(AttendanceRecord $record, User $reviewer): Timesheet
    {
        if ($record->employee_id === $reviewer->employee?->id) {
            throw ValidationException::withMessages(['attendance' => 'You cannot approve your own attendance record.']);
        }

        return DB::transaction(function () use ($record, $reviewer) {
            $record = AttendanceRecord::query()->lockForUpdate()->findOrFail($record->id);

            if ($record->check_out_at === null) {
                throw ValidationException::withMessages(['attendance' => 'Only completed attendance records can be approved.']);
            }

            $record->update([
                'approval_status' => 'approved',
                'approved_by' => $reviewer->id,
                'approved_at' => now(),
                'rejection_reason' => null,
            ]);

            return $this->syncApprovedAttendance($record);
        });
    }

    public function rejectAttendance(AttendanceRecord $record, User $reviewer, string $reason): void
    {
        if ($record->employee_id === $reviewer->employee?->id) {
            throw ValidationException::withMessages(['attendance' => 'You cannot reject your own attendance record.']);
        }

        DB::transaction(function () use ($record, $reviewer, $reason): void {
            $record = AttendanceRecord::query()->lockForUpdate()->findOrFail($record->id);
            $entry = TimesheetEntry::query()->where('attendance_record_id', $record->id)->first();

            if ($entry && in_array($entry->timesheet->status, ['submitted', 'approved'], true)) {
                throw ValidationException::withMessages(['attendance' => 'This record belongs to a locked timesheet and cannot be rejected.']);
            }

            $timesheet = $entry?->timesheet;
            $entry?->delete();
            if ($timesheet) {
                $this->recalculate($timesheet);
            }

            $record->update([
                'approval_status' => 'rejected',
                'approved_by' => $reviewer->id,
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ]);
        });
    }

    public function syncApprovedAttendance(AttendanceRecord $record): Timesheet
    {
        $date = Carbon::parse($record->attendance_date, config('workforce.timezone'));
        $periodStart = $date->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $periodEnd = $date->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();
        $timesheet = Timesheet::query()->firstOrCreate(
            [
                'employee_id' => $record->employee_id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
            ['status' => 'draft'],
        );

        if (in_array($timesheet->status, ['submitted', 'approved'], true)) {
            throw ValidationException::withMessages(['attendance' => 'The related timesheet is already locked for review.']);
        }

        $regularMinutes = max(0, $record->worked_minutes - $record->overtime_minutes);
        TimesheetEntry::query()->updateOrCreate(
            ['attendance_record_id' => $record->id],
            [
                'timesheet_id' => $timesheet->id,
                'work_date' => $record->attendance_date,
                'regular_minutes' => $regularMinutes,
                'overtime_minutes' => $record->overtime_minutes,
                'late_minutes' => $record->late_minutes,
                'undertime_minutes' => $record->undertime_minutes,
                'scheduled_minutes' => $record->scheduleAssignment?->shift?->duration_minutes,
                'source' => 'attendance',
                'notes' => $record->notes,
            ],
        );

        return $this->recalculate($timesheet);
    }

    public function submit(Timesheet $timesheet, User $employeeUser): Timesheet
    {
        if ($timesheet->employee_id !== $employeeUser->employee?->id) {
            abort(403);
        }

        if (! in_array($timesheet->status, ['draft', 'rejected'], true) || ! $timesheet->entries()->exists()) {
            throw ValidationException::withMessages(['timesheet' => 'Only a non-empty draft or rejected timesheet can be submitted.']);
        }

        $timesheet->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'reviewed_by' => null,
            'reviewed_at' => null,
            'reviewer_notes' => null,
        ]);

        return $timesheet->refresh();
    }

    public function review(Timesheet $timesheet, User $reviewer, string $decision, ?string $notes): Timesheet
    {
        if ($timesheet->employee_id === $reviewer->employee?->id) {
            throw ValidationException::withMessages(['timesheet' => 'You cannot review your own timesheet.']);
        }

        if ($timesheet->status !== 'submitted') {
            throw ValidationException::withMessages(['timesheet' => 'Only submitted timesheets can be reviewed.']);
        }

        $timesheet->update([
            'status' => $decision,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'reviewer_notes' => $notes,
        ]);

        return $timesheet->refresh();
    }

    public function recalculate(Timesheet $timesheet): Timesheet
    {
        $totals = $timesheet->entries()
            ->reorder()
            ->selectRaw('COALESCE(SUM(regular_minutes), 0) as regular_minutes')
            ->selectRaw('COALESCE(SUM(overtime_minutes), 0) as overtime_minutes')
            ->selectRaw('COALESCE(SUM(late_minutes), 0) as late_minutes')
            ->selectRaw('COALESCE(SUM(undertime_minutes), 0) as undertime_minutes')
            ->first();

        $timesheet->update([
            'regular_minutes' => (int) $totals->regular_minutes,
            'overtime_minutes' => (int) $totals->overtime_minutes,
            'late_minutes' => (int) $totals->late_minutes,
            'undertime_minutes' => (int) $totals->undertime_minutes,
        ]);

        return $timesheet->refresh();
    }
}
