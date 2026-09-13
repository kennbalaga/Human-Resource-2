<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\Holiday;
use App\Models\OfficeLocation;
use App\Models\ScheduleAssignment;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\TimesheetService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * A clean month of attendance for the demo employee, so the payslip screens
 * have something realistic to show.
 *
 * Not called from DatabaseSeeder: it writes a month of records against one
 * account, which a fresh install or the test suite has no use for. Run it on
 * purpose:
 *
 *     php artisan db:seed --class=PayslipDemoSeeder
 *
 * It follows the account's published roster for August 2026 (weekdays less
 * holidays when there is none), with one late arrival, one overtime evening and
 * one missed day so the payslip has something in each row. Everything goes
 * through TimesheetService, the way approved attendance really reaches a
 * timesheet. The first half of the month is fully approved; in the second the
 * last two weeks are left submitted and draft, so that payslip reads as partial
 * and approving the week of Aug 24 live shows it filling in.
 *
 * Safe to run twice: dates that already have attendance are left alone, and a
 * week is only moved forward from draft.
 */
class PayslipDemoSeeder extends Seeder
{
    private const EMPLOYEE = 'employee@hrms.local';

    private const REVIEWER = 'hr.manager@hrms.local';

    private const LATE_DAY = '2026-08-12';

    private const OVERTIME_DAY = '2026-08-06';

    private const MISSED_DAY = '2026-08-26';

    /** Weeks, by Monday, left short of approval. */
    private const SUBMITTED_WEEK = '2026-08-24';

    private const DRAFT_WEEK = '2026-08-31';

    public function run(TimesheetService $timesheets): void
    {
        $employeeUser = User::query()->where('email', self::EMPLOYEE)->firstOrFail();
        $employee = $employeeUser->employee;
        $reviewer = User::query()->where('email', self::REVIEWER)->firstOrFail();
        $officeId = OfficeLocation::query()->value('id');
        $timezone = config('workforce.timezone', 'Asia/Manila');

        $created = 0;

        foreach ($this->workingDays($employee->id) as $date => $shift) {
            if ($date === self::MISSED_DAY || AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('attendance_date', $date)->exists()) {
                continue;
            }

            $shiftStart = Carbon::parse("{$date} {$shift['start']}", $timezone);
            $shiftEnd = Carbon::parse("{$date} {$shift['end']}", $timezone);
            $checkIn = $date === self::LATE_DAY ? $shiftStart->copy()->addMinutes(15) : $shiftStart->copy()->subMinutes(5);
            $checkOut = $date === self::OVERTIME_DAY ? $shiftEnd->copy()->addHour() : $shiftEnd->copy()->addMinutes(2);

            $late = max(0, (int) $shiftStart->diffInMinutes($checkIn, false));
            $overtime = $date === self::OVERTIME_DAY ? 60 : 0;
            $worked = (int) $shiftStart->diffInMinutes($shiftEnd) - $shift['break'] - $late + $overtime;

            $record = AttendanceRecord::query()->create([
                'employee_id' => $employee->id,
                'office_location_id' => $officeId,
                'attendance_date' => $date,
                'check_in_at' => $checkIn->utc(),
                'check_out_at' => $checkOut->utc(),
                'status' => $late > 0 ? 'late' : 'present',
                'approval_status' => 'approved',
                'approved_by' => $reviewer->id,
                'approved_at' => now(),
                'late_minutes' => $late,
                'undertime_minutes' => 0,
                'overtime_minutes' => $overtime,
                'worked_minutes' => $worked,
                'schedule_assignment_id' => $shift['assignment_id'],
                'shift_start_at' => $shiftStart->copy()->utc(),
                'shift_end_at' => $shiftEnd->copy()->utc(),
                'notes' => 'Payslip demo data',
            ]);

            $timesheets->syncApprovedAttendance($record);
            $created++;
        }

        $weeks = Timesheet::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('period_start', ['2026-07-27', '2026-08-31'])
            ->orderBy('period_start')
            ->get();

        foreach ($weeks as $week) {
            $monday = $week->period_start->toDateString();

            if ($week->status !== 'draft' || $monday === self::DRAFT_WEEK || ! $week->entries()->exists()) {
                continue;
            }

            $timesheets->submit($week, $employeeUser);

            if ($monday !== self::SUBMITTED_WEEK) {
                $timesheets->review($week->refresh(), $reviewer, 'approved', 'Payslip demo data');
            }
        }

        $this->command?->info("Payslip demo: {$created} attendance day(s) added for ".self::EMPLOYEE.'.');
    }

    /**
     * The days the employee was expected at work in August 2026, keyed by date.
     *
     * @return Collection<string, array{start: string, end: string, break: int, assignment_id: ?int}>
     */
    private function workingDays(int $employeeId): Collection
    {
        $rostered = ScheduleAssignment::query()
            ->with('shift')
            ->where('employee_id', $employeeId)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', ['2026-08-01', '2026-08-31 23:59:59'])
            ->orderBy('work_date')
            ->get()
            ->filter(fn (ScheduleAssignment $assignment) => $assignment->shift !== null)
            ->mapWithKeys(fn (ScheduleAssignment $assignment) => [$assignment->work_date->toDateString() => [
                'start' => substr((string) $assignment->shift->start_time, 0, 5),
                'end' => substr((string) $assignment->shift->end_time, 0, 5),
                'break' => (int) $assignment->shift->break_minutes,
                'assignment_id' => $assignment->id,
            ]]);

        if ($rostered->isNotEmpty()) {
            return $rostered;
        }

        $holidays = Holiday::query()->whereBetween('date', ['2026-08-01', '2026-08-31 23:59:59'])->get()
            ->map(fn (Holiday $holiday) => $holiday->date->toDateString());

        return collect(CarbonPeriod::create('2026-08-01', '2026-08-31'))
            ->map(fn ($date) => Carbon::instance($date))
            ->reject(fn (Carbon $date) => $date->isWeekend() || $holidays->contains($date->toDateString()))
            ->mapWithKeys(fn (Carbon $date) => [$date->toDateString() => ['start' => '08:00', 'end' => '17:00', 'break' => 60, 'assignment_id' => null]]);
    }
}
