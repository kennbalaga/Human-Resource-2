<?php

namespace Tests\Feature\Security;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveAttachment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficeLocation;
use App\Models\PreferredDayOff;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ConfirmsDownloadPassword;
use Tests\TestCase;

/**
 * A department head holds the same role slugs as HR on every workforce screen,
 * but runs one unit. Before these checks existed the role alone opened the
 * whole hospital: any head could approve another ward's leave, download its
 * medical certificates, and roster its nurses.
 *
 * The org-wide roles are asserted alongside each case on purpose. The scoping
 * helpers distinguish "supervises everything" (null) from "supervises nothing"
 * (an empty list), and collapsing those two is the obvious way for this to go
 * wrong — it fails closed, which looks like nothing is broken until HR reports
 * an empty screen.
 */
class DepartmentScopingTest extends TestCase
{
    use ConfirmsDownloadPassword, RefreshDatabase;

    private User $departmentHead;

    private User $hrManager;

    private Employee $outsideEmployee;

    private Employee $ownEmployee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->departmentHead = User::query()->where('email', 'nursing.head@hrms.local')->firstOrFail();
        $this->hrManager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        // Seeded into HR; the head above runs DERM.
        $this->outsideEmployee = User::query()->where('email', 'employee@hrms.local')->firstOrFail()->employee;

        $this->ownEmployee = Employee::query()
            ->where('department_id', $this->departmentHead->employee->department_id)
            ->whereKeyNot($this->departmentHead->employee->id)
            ->firstOrFail();

        $this->assertNotSame(
            $this->outsideEmployee->department_id,
            $this->departmentHead->employee->department_id,
            'The fixture only means anything if the two employees are in different departments.',
        );
    }

    private function leaveFor(Employee $employee): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->firstOrFail()->id,
            'start_date' => '2027-06-07',
            'end_date' => '2027-06-09',
            'requested_days' => 3,
            'reason' => 'Fixture leave request for the scoping tests.',
            'status' => 'pending',
        ]);
    }

    public function test_a_head_cannot_approve_leave_for_another_department(): void
    {
        $leave = $this->leaveFor($this->outsideEmployee);

        $this->actingAs($this->departmentHead)
            ->post(route('leaves.approve', $leave), ['reviewer_notes' => 'Not mine to approve.'])
            ->assertForbidden();

        $this->assertSame('pending', $leave->refresh()->status);
    }

    public function test_a_head_can_still_approve_leave_inside_their_own_department(): void
    {
        $leave = $this->leaveFor($this->ownEmployee);

        $this->actingAs($this->departmentHead)
            ->post(route('leaves.approve', $leave), ['reviewer_notes' => 'Coverage confirmed.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $leave->refresh()->status);
    }

    public function test_hr_keeps_approval_across_every_department(): void
    {
        $leave = $this->leaveFor($this->outsideEmployee);

        $this->actingAs($this->hrManager)
            ->post(route('leaves.approve', $leave), ['reviewer_notes' => 'HR runs the whole hospital.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $leave->refresh()->status);
    }

    public function test_a_head_cannot_download_another_departments_medical_certificate(): void
    {
        Storage::fake('local');
        $leave = $this->leaveFor($this->outsideEmployee);
        Storage::disk('local')->put('leave-attachments/'.$leave->uuid.'/note.pdf', 'fit note');

        $attachment = LeaveAttachment::query()->create([
            'leave_request_id' => $leave->id,
            'disk' => 'local',
            'path' => 'leave-attachments/'.$leave->uuid.'/note.pdf',
            'original_name' => 'note.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
            'uploaded_by' => $this->outsideEmployee->user_id,
        ]);

        $this->actingAs($this->departmentHead)
            ->get(route('leave-attachments.download', $attachment))
            ->assertForbidden();

        // This app logs a session out the moment the authenticated user changes
        // (authenticateSessions(), a real protection), so the second identity
        // needs a session of its own.
        $this->flushSession();

        $this->actingAs($this->hrManager)
            ->get(route('leave-attachments.download', $attachment))
            ->assertOk();
    }

    public function test_the_leave_list_a_head_sees_stops_at_their_own_department(): void
    {
        $outside = $this->leaveFor($this->outsideEmployee);
        $own = $this->leaveFor($this->ownEmployee);

        $this->actingAs($this->departmentHead)
            ->get(route('leaves.index', ['year' => 2027]))
            ->assertOk()
            ->assertSee($own->employee->full_name)
            ->assertDontSee($outside->employee->full_name);
    }

    public function test_hr_still_sees_every_department_in_the_leave_list(): void
    {
        $outside = $this->leaveFor($this->outsideEmployee);
        $own = $this->leaveFor($this->ownEmployee);

        $this->actingAs($this->hrManager)
            ->get(route('leaves.index', ['year' => 2027]))
            ->assertOk()
            ->assertSee($outside->employee->full_name)
            ->assertSee($own->employee->full_name);
    }

    public function test_a_head_cannot_roster_an_employee_from_another_department(): void
    {
        $this->actingAs($this->departmentHead)
            ->post(route('schedules.store'), [
                'employee_id' => $this->outsideEmployee->id,
                'shift_id' => Shift::query()->where('is_active', true)->firstOrFail()->id,
                'work_date' => '2027-06-07',
            ])
            ->assertSessionHasErrors('employee_id');

        $this->assertDatabaseMissing('schedule_assignments', [
            'employee_id' => $this->outsideEmployee->id,
            'work_date' => '2027-06-07',
        ]);
    }

    public function test_a_head_cannot_take_another_departments_shift_by_moving_it_onto_their_own_staff(): void
    {
        $shift = Shift::query()->where('is_active', true)->firstOrFail();
        $assignment = RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $this->outsideEmployee->id,
            'shift_id' => $shift->id,
            'work_date' => '2027-06-07',
            'status' => 'scheduled',
            'created_by' => $this->hrManager->id,
        ]));

        // The new employee_id is the head's own nurse, so the request rules
        // pass; it is the assignment being edited that is not theirs.
        $this->actingAs($this->departmentHead)
            ->put(route('schedules.update', $assignment), [
                'employee_id' => $this->ownEmployee->id,
                'shift_id' => $shift->id,
                'work_date' => '2027-06-07',
            ])
            ->assertForbidden();

        $this->assertSame($this->outsideEmployee->id, $assignment->refresh()->employee_id);
    }

    public function test_a_head_cannot_force_an_attendance_punch_for_another_department(): void
    {
        $this->actingAs($this->departmentHead)
            ->post(route('attendance.override.check-in'), [
                'employee_id' => $this->outsideEmployee->id,
                'office_location_id' => OfficeLocation::query()->where('is_active', true)->firstOrFail()->id,
                'reason' => 'Attempting a punch outside the supervised ward.',
            ])
            ->assertForbidden();
    }

    /**
     * Split into two tests, one identity each. Sanctum resolves the bearer
     * token once per request lifecycle and the guard holds that user for the
     * rest of it, so swapping the Authorization header inside a single test
     * silently keeps answering as the first token.
     */
    public function test_the_api_employee_list_is_scoped_for_a_department_head(): void
    {
        Sanctum::actingAs($this->departmentHead, ['workforce:read', 'workforce:write']);

        // Asking explicitly for the other department: the filter narrows, it
        // does not widen, so the answer is an empty page rather than HR's staff.
        $this->getJson('/api/v1/employees?department_id='.$this->outsideEmployee->department_id)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/employees')
            ->assertOk()
            ->assertJsonPath('meta.total', Employee::query()
                ->where('department_id', $this->departmentHead->employee->department_id)
                ->count());
    }

    public function test_the_api_employee_list_stays_whole_for_hr(): void
    {
        Sanctum::actingAs($this->hrManager, ['workforce:read', 'workforce:write']);

        $this->getJson('/api/v1/employees?department_id='.$this->outsideEmployee->department_id)
            ->assertOk()
            ->assertJsonFragment(['employee_number' => $this->outsideEmployee->employee_number]);

        $this->getJson('/api/v1/employees')
            ->assertOk()
            ->assertJsonPath('meta.total', Employee::query()->count());
    }

    public function test_the_api_refuses_a_head_reading_another_departments_leave(): void
    {
        $leave = $this->leaveFor($this->outsideEmployee);
        Sanctum::actingAs($this->departmentHead, ['workforce:read', 'workforce:write']);

        $this->getJson('/api/v1/leaves/'.$leave->id)->assertForbidden();
    }

    /**
     * Exports matter more than screens: a CSV is the whole result set in one
     * request, so an unscoped one hands over every ward at once rather than a
     * page at a time.
     */
    public function test_an_attendance_export_stops_at_the_supervised_department(): void
    {
        $outside = $this->attendanceFor($this->outsideEmployee);
        $own = $this->attendanceFor($this->ownEmployee);

        $csv = $this->actingAs($this->departmentHead)
            ->get(route('attendance.reports.export', ['date_from' => '2027-06-07', 'date_to' => '2027-06-07']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString($own->employee->employee_number, $csv);
        $this->assertStringNotContainsString($outside->employee->employee_number, $csv);
    }

    public function test_an_attendance_export_stays_whole_for_hr(): void
    {
        $outside = $this->attendanceFor($this->outsideEmployee);

        $csv = $this->actingAs($this->hrManager)
            ->get(route('attendance.reports.export', ['date_from' => '2027-06-07', 'date_to' => '2027-06-07']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString($outside->employee->employee_number, $csv);
    }

    public function test_the_attendance_report_screen_stops_at_the_supervised_department(): void
    {
        $outside = $this->attendanceFor($this->outsideEmployee);
        $own = $this->attendanceFor($this->ownEmployee);

        $this->actingAs($this->departmentHead)
            ->get(route('attendance.reports.index', ['date_from' => '2027-06-07', 'date_to' => '2027-06-07']))
            ->assertOk()
            ->assertSee($own->employee->full_name)
            ->assertDontSee($outside->employee->full_name);
    }

    public function test_a_head_cannot_approve_another_departments_attendance(): void
    {
        $record = $this->attendanceFor($this->outsideEmployee);

        $this->actingAs($this->departmentHead)
            ->post(route('attendance.records.approve', $record))
            ->assertForbidden();

        $this->assertSame('pending', $record->refresh()->approval_status);
    }

    private function attendanceFor(Employee $employee): AttendanceRecord
    {
        return AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'office_location_id' => OfficeLocation::query()->where('is_active', true)->firstOrFail()->id,
            'attendance_date' => '2027-06-07',
            'check_in_at' => '2027-06-07 08:00:00',
            'check_out_at' => '2027-06-07 17:00:00',
            'status' => 'present',
            'approval_status' => 'pending',
            'worked_minutes' => 480,
            'check_in_method' => 'manual',
        ]);
    }

    /**
     * This page had no test at all, which is how a missing trait import on its
     * controller survived a green suite — the class is autoloaded lazily, so
     * nothing loaded it until a real request did.
     */
    public function test_the_day_off_queue_a_head_reviews_stops_at_their_department(): void
    {
        $outside = $this->dayOffFor($this->outsideEmployee);
        $own = $this->dayOffFor($this->ownEmployee);

        $this->actingAs($this->departmentHead)
            ->get(route('schedule-preferences.index'))
            ->assertOk()
            ->assertSee($own->employee->full_name)
            ->assertDontSee($outside->employee->full_name);
    }

    public function test_a_head_cannot_approve_another_departments_day_off(): void
    {
        $outside = $this->dayOffFor($this->outsideEmployee);

        $this->actingAs($this->departmentHead)
            ->post(route('schedule-preferences.approve-day-off', $outside))
            ->assertForbidden();

        $this->assertSame('pending', $outside->refresh()->status);
    }

    public function test_hr_still_reviews_day_offs_across_every_department(): void
    {
        $outside = $this->dayOffFor($this->outsideEmployee);

        $this->actingAs($this->hrManager)
            ->get(route('schedule-preferences.index'))
            ->assertOk()
            ->assertSee($outside->employee->full_name);
    }

    private function dayOffFor(Employee $employee): PreferredDayOff
    {
        return PreferredDayOff::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'preferred_date' => '2027-06-07',
            'reason' => 'Fixture day-off request for the scoping tests.',
            'status' => 'pending',
        ]);
    }

    public function test_the_supervision_helpers_separate_everything_from_nothing(): void
    {
        // The distinction the whole feature rests on, asserted directly so a
        // regression names itself rather than surfacing as an empty screen.
        $this->assertNull($this->hrManager->supervisedDepartmentIds());
        $this->assertSame(
            [$this->departmentHead->employee->department_id],
            $this->departmentHead->supervisedDepartmentIds(),
        );
        $this->assertSame([], User::query()->where('email', 'employee@hrms.local')->firstOrFail()->supervisedDepartmentIds());

        $this->assertTrue($this->hrManager->supervises($this->outsideEmployee));
        $this->assertFalse($this->departmentHead->supervises($this->outsideEmployee));
        $this->assertTrue($this->departmentHead->supervises($this->ownEmployee));
    }

    public function test_a_head_only_sees_their_own_unit_in_the_department_filter(): void
    {
        $this->assertGreaterThan(1, Department::query()->where('is_active', true)->count());

        $this->actingAs($this->departmentHead)
            ->get(route('leaves.index', ['year' => 2027]))
            ->assertOk()
            ->assertViewHas('departments', fn ($departments) => $departments->count() === 1
                && $departments->first()->id === $this->departmentHead->employee->department_id);
    }
}
