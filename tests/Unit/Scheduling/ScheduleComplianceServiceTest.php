<?php

namespace Tests\Unit\Scheduling;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use App\Services\Scheduling\ScheduleComplianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleComplianceServiceTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Employee $employee;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->department = Department::query()->where('code', 'HR')->firstOrFail();
        $this->employee = Employee::query()->where('employee_number', 'HR-2026-0002')->firstOrFail();
        $this->shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();

        // A clean slate for this employee — the seeded demo roster would
        // otherwise mix legacy/unaudited rows into every assertion here.
        ScheduleAssignment::query()->where('employee_id', $this->employee->id)->delete();
    }

    public function test_a_freshly_created_assignment_produces_no_provenance_findings(): void
    {
        $this->assign('2027-09-14', 'manual');

        $findings = $this->reviewFindings();

        $this->assertEmpty($findings->whereIn('rule', ['missing_provenance', 'legacy_provenance', 'unaudited_provenance']));
    }

    public function test_a_legacy_assignment_produces_a_legacy_provenance_warning(): void
    {
        $this->assign('2027-09-14', 'legacy');

        $finding = $this->reviewFindings()->firstWhere('rule', 'legacy_provenance');

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame($this->employee->id, $finding['employee_id']);
    }

    public function test_an_assignment_with_no_audit_row_produces_an_unaudited_provenance_warning(): void
    {
        $assignment = $this->assign('2027-09-14', 'manual');
        // Simulate data that predates Layer 5 — a raw delete bypasses the
        // audit model's append-only guard, same as history that simply never
        // had a row written in the first place.
        DB::table('schedule_assignment_audits')->where('schedule_assignment_id', $assignment->id)->delete();

        $finding = $this->reviewFindings()->firstWhere('rule', 'unaudited_provenance');

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding['severity']);
    }

    public function test_an_off_shift_attendance_record_produces_a_warning(): void
    {
        AttendanceRecord::query()->create([
            'employee_id' => $this->employee->id,
            'attendance_date' => '2027-09-14',
            'check_in_at' => '2027-09-14 09:00:00',
            'check_in_method' => 'manual',
            'status' => 'present',
            'binding_source' => 'override',
            'schedule_status' => 'unscheduled',
        ]);

        $finding = $this->reviewFindings()->firstWhere('rule', 'off_shift_attendance');

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding['severity']);
    }

    public function test_a_manager_overridden_attendance_record_produces_a_warning(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        AttendanceRecord::query()->create([
            'employee_id' => $this->employee->id,
            'attendance_date' => '2027-09-14',
            'check_in_at' => '2027-09-14 09:00:00',
            'check_in_method' => 'manual',
            'status' => 'present',
            'binding_source' => 'override',
            'schedule_status' => 'unscheduled',
            'override_authorised_by' => $manager->id,
            'override_reason' => 'Test override.',
        ]);

        $finding = $this->reviewFindings()->firstWhere('rule', 'manager_overridden_attendance');

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding['severity']);
    }

    public function test_an_on_shift_attendance_record_produces_no_adherence_findings(): void
    {
        AttendanceRecord::query()->create([
            'employee_id' => $this->employee->id,
            'attendance_date' => '2027-09-14',
            'check_in_at' => '2027-09-14 08:00:00',
            'check_in_method' => 'manual',
            'status' => 'present',
            'binding_source' => 'scheduled',
            'schedule_status' => 'on_shift',
        ]);

        $findings = $this->reviewFindings();

        $this->assertEmpty($findings->whereIn('rule', ['off_shift_attendance', 'manager_overridden_attendance']));
    }

    private function assign(string $date, string $createdVia): ScheduleAssignment
    {
        return RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->shift->id,
            'work_date' => $date,
            'status' => 'scheduled',
            'created_via' => $createdVia,
            'created_by' => User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail()->id,
        ]));
    }

    private function reviewFindings(): Collection
    {
        $review = app(ScheduleComplianceService::class)->review($this->department, '2027-09-14', '2027-09-14');

        return collect($review->findings)->where('employee_id', $this->employee->id);
    }
}
