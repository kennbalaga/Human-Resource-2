<?php

namespace Tests\Feature\Timesheet;

use App\Models\AttendanceRecord;
use App\Models\OfficeLocation;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimesheetWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_approved_completed_attendance_creates_weekly_timesheet_entry(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $attendance = $this->completedAttendance($employee, '2027-05-03');

        $this->actingAs($manager)->post(route('attendance.records.approve', $attendance))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('attendance_records', ['id' => $attendance->id, 'approval_status' => 'approved']);
        $this->assertDatabaseHas('timesheets', ['employee_id' => $employee->employee->id, 'period_start' => '2027-05-03 00:00:00', 'status' => 'draft']);
        $this->assertDatabaseHas('timesheet_entries', ['attendance_record_id' => $attendance->id, 'regular_minutes' => 480, 'overtime_minutes' => 30]);
    }

    public function test_incomplete_attendance_cannot_be_approved(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $attendance = AttendanceRecord::query()->create([
            'employee_id' => $employee->employee->id,
            'office_location_id' => OfficeLocation::query()->value('id'),
            'attendance_date' => '2027-05-04',
            'check_in_at' => '2027-05-04 08:00:00',
            'status' => 'present',
        ]);

        $this->actingAs($manager)->post(route('attendance.records.approve', $attendance))->assertSessionHasErrors('attendance');
        $this->assertDatabaseCount('timesheets', 0);
    }

    public function test_employee_submits_and_manager_approves_timesheet(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $attendance = $this->completedAttendance($employee, '2027-05-05');
        $this->actingAs($manager)->post(route('attendance.records.approve', $attendance));
        $timesheet = Timesheet::query()->firstOrFail();

        $this->flushSession();
        $this->actingAs($employee)->post(route('timesheets.submit', $timesheet))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('timesheets', ['id' => $timesheet->id, 'status' => 'submitted']);

        $this->flushSession();
        $this->actingAs($manager)->post(route('timesheets.approve', $timesheet), ['reviewer_notes' => 'Verified'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('timesheets', ['id' => $timesheet->id, 'status' => 'approved', 'reviewed_by' => $manager->id]);
    }

    public function test_employee_can_view_own_timesheets_and_export(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($employee)->get('/timesheets')->assertOk()->assertSee('Timesheet Management');
        $this->actingAs($employee)->get('/timesheets/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    private function completedAttendance(User $employee, string $date): AttendanceRecord
    {
        return AttendanceRecord::query()->create([
            'employee_id' => $employee->employee->id,
            'office_location_id' => OfficeLocation::query()->value('id'),
            'attendance_date' => $date,
            'check_in_at' => $date.' 08:00:00',
            'check_out_at' => $date.' 17:30:00',
            'status' => 'present',
            'worked_minutes' => 510,
            'overtime_minutes' => 30,
        ]);
    }
}
