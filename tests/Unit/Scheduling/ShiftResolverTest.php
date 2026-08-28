<?php

namespace Tests\Unit\Scheduling;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficeLocation;
use App\Models\Position;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use App\Services\Scheduling\ShiftResolution;
use App\Services\Scheduling\ShiftResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShiftResolverTest extends TestCase
{
    use RefreshDatabase;

    private const EARLY_WINDOW = 60;

    private const GRACE = 15;

    private const LATE_BIND = 240;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_it_binds_and_classifies_on_shift_within_grace(): void
    {
        $employee = $this->employee();
        $this->assign($employee, $this->shift('08:00:00', '17:00:00'), '2027-08-24');

        $resolution = $this->resolve($employee, '2027-08-24 08:10:00');

        $this->assertTrue($resolution->isBound());
        $this->assertSame(ShiftResolution::ON_SHIFT, $resolution->scheduleStatus);
    }

    public function test_it_classifies_early_arrival(): void
    {
        $employee = $this->employee();
        $this->assign($employee, $this->shift('08:00:00', '17:00:00'), '2027-08-24');

        $resolution = $this->resolve($employee, '2027-08-24 07:30:00');

        $this->assertTrue($resolution->isBound());
        $this->assertSame(ShiftResolution::EARLY, $resolution->scheduleStatus);
    }

    public function test_it_classifies_late_arrival_within_late_bind(): void
    {
        $employee = $this->employee();
        $this->assign($employee, $this->shift('08:00:00', '17:00:00'), '2027-08-24');

        $resolution = $this->resolve($employee, '2027-08-24 09:30:00');

        $this->assertTrue($resolution->isBound());
        $this->assertSame(ShiftResolution::LATE, $resolution->scheduleStatus);
    }

    public function test_boundary_classification_around_grace_and_late_bind(): void
    {
        $employee = $this->employee();
        $this->assign($employee, $this->shift('08:00:00', '17:00:00'), '2027-08-24');

        // start (08:00) — exactly on time.
        $this->assertSame(ShiftResolution::ON_SHIFT, $this->resolve($employee, '2027-08-24 08:00:00')->scheduleStatus);
        // start + grace (08:15) — still on time.
        $this->assertSame(ShiftResolution::ON_SHIFT, $this->resolve($employee, '2027-08-24 08:15:00')->scheduleStatus);
        // start + grace + 1 minute (08:16) — late.
        $this->assertSame(ShiftResolution::LATE, $this->resolve($employee, '2027-08-24 08:16:00')->scheduleStatus);
        // start + late_bind (12:00) — still binds, still late.
        $this->assertSame(ShiftResolution::LATE, $this->resolve($employee, '2027-08-24 12:00:00')->scheduleStatus);
        // start + late_bind + 1 minute (12:01) — outside the window entirely.
        $this->assertFalse($this->resolve($employee, '2027-08-24 12:01:00')->isBound());
        // start - early_window (07:00) — earliest a punch still binds.
        $this->assertSame(ShiftResolution::EARLY, $this->resolve($employee, '2027-08-24 07:00:00')->scheduleStatus);
        // start - early_window - 1 minute (06:59) — outside the window entirely.
        $this->assertFalse($this->resolve($employee, '2027-08-24 06:59:00')->isBound());
    }

    public function test_a_punch_far_outside_any_shift_window_is_unscheduled(): void
    {
        // The original defect: a 14:00-22:00 shift, badged in at 09:00.
        $employee = $this->employee();
        $this->assign($employee, $this->shift('14:00:00', '22:00:00'), '2027-08-24');

        $resolution = $this->resolve($employee, '2027-08-24 09:00:00');

        $this->assertFalse($resolution->isBound());
        $this->assertSame(ShiftResolution::UNSCHEDULED, $resolution->scheduleStatus);
        $this->assertSame(ShiftResolution::REASON_NO_CANDIDATES, $resolution->reason);
    }

    public function test_overnight_shift_rolls_the_end_to_the_next_day(): void
    {
        $employee = $this->employee();
        $this->assign($employee, $this->shift('22:00:00', '06:00:00'), '2027-08-24');

        $resolution = $this->resolve($employee, '2027-08-24 22:05:00');

        $this->assertTrue($resolution->isBound());
        $this->assertSame(ShiftResolution::ON_SHIFT, $resolution->scheduleStatus);
        $this->assertSame('2027-08-25 06:00:00', $resolution->end()->format('Y-m-d H:i:s'));
    }

    public function test_approved_leave_blocks_binding_with_leave_conflict_reason(): void
    {
        $employee = $this->employee();
        $this->assign($employee, $this->shift('08:00:00', '17:00:00'), '2027-08-24');

        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->value('id'),
            'start_date' => '2027-08-24',
            'end_date' => '2027-08-24',
            'requested_days' => 1,
            'reason' => 'Approved for the shift resolver test.',
            'status' => 'approved',
        ]);

        $resolution = $this->resolve($employee, '2027-08-24 08:10:00');

        $this->assertFalse($resolution->isBound());
        $this->assertSame(ShiftResolution::REASON_LEAVE_CONFLICT, $resolution->reason);
    }

    public function test_a_closed_attendance_record_removes_the_assignment_from_candidacy(): void
    {
        $employee = $this->employee();
        $assignment = $this->assign($employee, $this->shift('08:00:00', '17:00:00'), '2027-08-24');

        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'office_location_id' => OfficeLocation::query()->value('id'),
            'attendance_date' => '2027-08-24',
            'check_in_at' => Carbon::parse('2027-08-24 08:00:00', 'Asia/Manila'),
            'check_out_at' => Carbon::parse('2027-08-24 17:00:00', 'Asia/Manila'),
            'check_in_method' => 'manual',
            'check_out_method' => 'manual',
            'status' => 'present',
            'schedule_assignment_id' => $assignment->id,
        ]);

        $resolution = $this->resolve($employee, '2027-08-24 08:05:00');

        $this->assertFalse($resolution->isBound());
    }

    public function test_multiple_candidates_bind_to_the_nearest_start(): void
    {
        $employee = $this->employee();
        // Generous late-bind so both shifts remain candidates for a punch at 14:00.
        $this->assign($employee, $this->shift('10:00:00', '14:00:00', 'SR-A'), '2027-08-24');
        $secondAssignment = $this->assign($employee, $this->shift('14:30:00', '22:00:00', 'SR-B'), '2027-08-24');

        $resolution = $this->resolve($employee, '2027-08-24 14:00:00', lateBindMinutes: 300);

        $this->assertTrue($resolution->isBound());
        $this->assertSame($secondAssignment->id, $resolution->assignment->id);
    }

    private function resolve(Employee $employee, string $at, ?int $lateBindMinutes = null): ShiftResolution
    {
        return app(ShiftResolver::class)->forPunch(
            $employee,
            Carbon::parse($at, 'Asia/Manila'),
            self::EARLY_WINDOW,
            self::GRACE,
            $lateBindMinutes ?? self::LATE_BIND,
        );
    }

    private function employee(): Employee
    {
        // A dedicated employee rather than reusing a seeded one: ShiftScheduleSeeder
        // gives the System Administrator (the first active employee by id) a real
        // Mon-Fri ADMIN-0800 assignment for "this week" relative to whenever the
        // suite runs, which on the date this file's shift dates happen to land on
        // would otherwise silently compete with the test's own fixture assignment
        // as a second, unwanted candidate for the resolver to bind to.
        $department = Department::query()->where('code', 'HR')->firstOrFail();
        $position = Position::query()->where('department_id', $department->id)->firstOrFail();

        return Employee::query()->create([
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employee_number' => 'SR-'.Str::random(8),
            'first_name' => 'Resolver',
            'last_name' => 'Test',
            'employment_status' => 'active',
            'hire_date' => '2024-01-01',
        ]);
    }

    private function actor(): User
    {
        return User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }

    private function shift(string $start, string $end, ?string $code = null): Shift
    {
        return Shift::query()->create([
            'code' => $code ?? 'SR-'.Str::random(6),
            'name' => 'Shift Resolver Test '.$start,
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
            'created_by' => $this->actor()->id,
        ]));
    }
}
