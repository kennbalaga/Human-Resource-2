<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\Employee;
use App\Models\OfficeLocation;
use App\Models\Position;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\Scheduling\AttendanceScheduleSettings;
use App\Services\Scheduling\RosterWriteContext;
use App\Services\TimesheetService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduleAwareAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_bound_check_in_and_check_out_are_timed_against_the_shift_when_schedule_aware_is_on(): void
    {
        $this->enableScheduleAware();
        $office = $this->office();
        $employee = $this->rosterableEmployee();
        $this->assign($employee, $this->shift('08:00:00', '17:00:00'), '2026-08-24');

        Carbon::setTestNow(Carbon::parse('2026-08-24 08:20:00', 'Asia/Manila'));
        $checkIn = app(AttendanceService::class)->checkIn($employee, $office, null, '127.0.0.1', 'PHPUnit');

        $this->assertSame('late', $checkIn->status);
        $this->assertSame('scheduled', $checkIn->binding_source);
        $this->assertSame('late', $checkIn->schedule_status);
        // 08:20 vs shift start 08:00 + 15 min grace = 08:15 -> 5 minutes late.
        $this->assertSame(5, $checkIn->late_minutes);
        $this->assertNotNull($checkIn->schedule_assignment_id);

        Carbon::setTestNow(Carbon::parse('2026-08-24 17:30:00', 'Asia/Manila'));
        $checkOut = app(AttendanceService::class)->checkOut($employee, $office, null, '127.0.0.1', 'PHPUnit');

        // Overtime measured against the frozen shift end (17:00), not office close.
        $this->assertSame(30, $checkOut->overtime_minutes);
    }

    public function test_check_in_is_refused_when_enforcement_is_on_and_no_shift_covers_the_punch(): void
    {
        $this->enableScheduleAware(enforce: true);
        $office = $this->office();
        $employee = $this->rosterableEmployee();

        Carbon::setTestNow(Carbon::parse('2026-08-24 08:00:00', 'Asia/Manila'));

        $this->expectException(ValidationException::class);

        app(AttendanceService::class)->checkIn($employee, $office, null, '127.0.0.1', 'PHPUnit');
    }

    public function test_a_manager_can_authorise_an_unscheduled_punch(): void
    {
        $this->enableScheduleAware(enforce: true);
        $office = $this->office();
        $employee = $this->rosterableEmployee();
        $manager = $this->manager();

        Carbon::setTestNow(Carbon::parse('2026-08-24 08:00:00', 'Asia/Manila'));

        $record = app(AttendanceService::class)->checkIn(
            $employee,
            $office,
            null,
            '127.0.0.1',
            'PHPUnit',
            'manual',
            null,
            null,
            $manager,
            'Bank nurse covering an unpublished shift.',
        );

        $this->assertSame('override', $record->binding_source);
        $this->assertSame('unscheduled', $record->schedule_status);
        $this->assertSame($manager->id, $record->override_authorised_by);
        $this->assertSame('Bank nurse covering an unpublished shift.', $record->override_reason);
    }

    public function test_a_manager_cannot_authorise_their_own_punch(): void
    {
        $this->enableScheduleAware(enforce: true);
        $office = $this->office();
        $manager = $this->manager();

        // ShiftScheduleSeeder gives the manager's own employee a real
        // Mon-Fri ADMIN-0800 assignment for "this week" relative to
        // whenever the suite runs, which on the date below would otherwise
        // cover this punch and defeat the "no shift covers it" premise this
        // test is actually about.
        RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()
            ->where('employee_id', $manager->employee->id)
            ->whereDate('work_date', '2026-08-24')
            ->delete());

        Carbon::setTestNow(Carbon::parse('2026-08-24 08:00:00', 'Asia/Manila'));

        $this->expectException(ValidationException::class);

        app(AttendanceService::class)->checkIn(
            $manager->employee,
            $office,
            null,
            '127.0.0.1',
            'PHPUnit',
            'manual',
            null,
            null,
            $manager,
            'Self-authorised, should be refused.',
        );
    }

    public function test_check_out_falls_back_to_office_hours_for_an_unbound_override_record(): void
    {
        $this->enableScheduleAware();
        $office = $this->office();
        $office->update(['work_start_time' => '08:00:00', 'work_end_time' => '17:00:00', 'grace_period_minutes' => 15, 'break_minutes' => 60]);
        $employee = $this->rosterableEmployee();

        Carbon::setTestNow(Carbon::parse('2026-08-24 08:00:00', 'Asia/Manila'));
        app(AttendanceService::class)->checkIn($employee, $office, null, '127.0.0.1', 'PHPUnit');

        Carbon::setTestNow(Carbon::parse('2026-08-24 17:30:00', 'Asia/Manila'));
        $checkOut = app(AttendanceService::class)->checkOut($employee, $office, null, '127.0.0.1', 'PHPUnit');

        // No bound shift end, so the office-hours formula applies even though
        // schedule_aware is on.
        $this->assertSame(30, $checkOut->overtime_minutes);
        $this->assertSame(0, $checkOut->undertime_minutes);
    }

    public function test_schedule_aware_off_preserves_office_hours_status_by_default(): void
    {
        $office = $this->office();
        // Office hours are deliberately lenient (early start, wide grace) so 08:20
        // reads on-time against them while still landing squarely inside the
        // shift's 08:00 start + 15 min grace late threshold.
        $office->update(['work_start_time' => '06:00:00', 'work_end_time' => '17:00:00', 'grace_period_minutes' => 180, 'break_minutes' => 60]);
        $employee = $this->rosterableEmployee();
        $this->assign($employee, $this->shift('08:00:00', '17:00:00'), '2026-08-24');

        // 08:20 is late against the shift (grace 08:15) but on-time against office hours.
        Carbon::setTestNow(Carbon::parse('2026-08-24 08:20:00', 'Asia/Manila'));
        $checkIn = app(AttendanceService::class)->checkIn($employee, $office, null, '127.0.0.1', 'PHPUnit');

        $this->assertSame('present', $checkIn->status);
        $this->assertSame(0, $checkIn->late_minutes);
        // The shadow columns still record the truth, even with the flag off.
        $this->assertSame('scheduled', $checkIn->binding_source);
        $this->assertSame('late', $checkIn->schedule_status);
    }

    public function test_a_punch_far_outside_the_rostered_shift_is_recorded_as_unscheduled_not_silently_present(): void
    {
        // The original defect, replayed against the new columns: rostered 14:00-22:00,
        // badges in at 09:00. It is nowhere near the shift's binding window, so the new
        // schedule_status/binding_source correctly flag it as unscheduled — the
        // auditable fix — even though `status` still falls back to office hours until
        // enforcement (Phase 4) is turned on. See the plan's §10 note on this deviation.
        $this->enableScheduleAware();
        $office = $this->office();
        $employee = $this->rosterableEmployee();
        $this->assign($employee, $this->shift('14:00:00', '22:00:00'), '2026-08-24');

        Carbon::setTestNow(Carbon::parse('2026-08-24 09:00:00', 'Asia/Manila'));
        $checkIn = app(AttendanceService::class)->checkIn($employee, $office, null, '127.0.0.1', 'PHPUnit');

        $this->assertSame('override', $checkIn->binding_source);
        $this->assertSame('unscheduled', $checkIn->schedule_status);
        $this->assertNull($checkIn->schedule_assignment_id);
    }

    public function test_timesheet_sync_populates_scheduled_minutes_for_a_bound_record(): void
    {
        $this->enableScheduleAware();
        $office = $this->office();
        $employee = $this->rosterableEmployee();
        $this->assign($employee, $this->shift('08:00:00', '17:00:00'), '2026-08-24');

        Carbon::setTestNow(Carbon::parse('2026-08-24 08:00:00', 'Asia/Manila'));
        app(AttendanceService::class)->checkIn($employee, $office, null, '127.0.0.1', 'PHPUnit');

        Carbon::setTestNow(Carbon::parse('2026-08-24 17:00:00', 'Asia/Manila'));
        $record = app(AttendanceService::class)->checkOut($employee, $office, null, '127.0.0.1', 'PHPUnit');

        $timesheet = app(TimesheetService::class)->approveAttendance($record, $this->manager());
        $entry = $timesheet->entries()->firstWhere('attendance_record_id', $record->id);

        // 08:00-17:00 shift, 60 minute break -> 480 rostered minutes.
        $this->assertSame(480, $entry->scheduled_minutes);
    }

    private function enableScheduleAware(bool $enforce = false): void
    {
        app(AttendanceScheduleSettings::class)->update($this->manager(), [
            'early_window_minutes' => 60,
            'grace_minutes' => 15,
            'late_bind_minutes' => 240,
            'schedule_aware' => true,
            'enforce_published_shift' => $enforce,
        ]);
    }

    private function office(): OfficeLocation
    {
        return OfficeLocation::query()->firstOrFail();
    }

    private function manager(): User
    {
        return User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }

    /**
     * An employee distinct from the manager, so override tests never
     * self-authorise by accident — and a freshly created one rather than a
     * seeded one: ShiftScheduleSeeder gives the System Administrator (the
     * first active employee by id) a real Mon-Fri ADMIN-0800 assignment for
     * "this week" relative to whenever the suite runs, which on the date
     * this file's shift dates happen to land on would otherwise silently
     * cover punches these tests expect to find no shift for at all.
     */
    private function rosterableEmployee(): Employee
    {
        $department = Department::query()->where('code', 'HR')->firstOrFail();
        $position = Position::query()->where('department_id', $department->id)->firstOrFail();

        return Employee::query()->create([
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employee_number' => 'SAT-'.Str::random(8),
            'first_name' => 'Attendance',
            'last_name' => 'Test',
            'employment_status' => 'active',
            'hire_date' => '2024-01-01',
        ]);
    }

    private function shift(string $start, string $end): Shift
    {
        return Shift::query()->create([
            'code' => 'SAT-'.Str::random(6),
            'name' => 'Schedule Aware Test '.$start,
            'start_time' => $start,
            'end_time' => $end,
            'break_minutes' => 60,
            'is_active' => true,
        ]);
    }

    private function assign(Employee $employee, Shift $shift, string $workDate): ScheduleAssignment
    {
        return RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'work_date' => $workDate,
            'status' => 'scheduled',
            'created_by' => $this->manager()->id,
        ]));
    }
}
