<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeaveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_employee_can_view_balances_and_submit_leave(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $vacation = LeaveType::query()->where('code', 'VAC')->firstOrFail();

        $this->actingAs($employee)->get('/leaves')->assertOk()->assertSee('Leave Management')->assertSee('15.0 days');
        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $vacation->id,
            'start_date' => '2027-06-07',
            'end_date' => '2027-06-09',
            'reason' => 'Scheduled family vacation leave.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('leave_requests', ['employee_id' => $employee->employee->id, 'requested_days' => 3, 'status' => 'pending']);
        $balance = LeaveBalance::query()->where('employee_id', $employee->employee->id)->where('leave_type_id', $vacation->id)->where('year', 2027)->firstOrFail();
        $this->assertSame(3.0, (float) $balance->pending_days);
    }

    public function test_manager_approval_moves_pending_days_to_used(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $leave = $this->submitVacation($employee);

        $this->flushSession();
        $this->actingAs($manager)->post(route('leaves.approve', $leave), ['reviewer_notes' => 'Coverage confirmed'])->assertSessionHasNoErrors();
        $balance = LeaveBalance::query()->where('employee_id', $employee->employee->id)->where('leave_type_id', $leave->leave_type_id)->where('year', 2027)->firstOrFail();

        $this->assertSame(0.0, (float) $balance->pending_days);
        $this->assertSame(3.0, (float) $balance->used_days);
        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id, 'status' => 'approved']);
        $this->actingAs($manager)->get('/schedules?date=2027-06-08')->assertOk()->assertSee('Vacation Leave');
    }

    public function test_cancelling_approved_leave_restores_balance(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $leave = $this->submitVacation($employee);

        $this->flushSession();
        $this->actingAs($manager)->post(route('leaves.approve', $leave));

        $this->flushSession();
        $this->actingAs($employee)->post(route('leaves.cancel', $leave))->assertSessionHasNoErrors();
        $balance = LeaveBalance::query()->where('employee_id', $employee->employee->id)->where('leave_type_id', $leave->leave_type_id)->where('year', 2027)->firstOrFail();
        $this->assertSame(0.0, (float) $balance->used_days);
        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id, 'status' => 'cancelled']);
    }

    public function test_overlapping_leave_is_rejected(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $leave = $this->submitVacation($employee);

        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $leave->leave_type_id,
            'start_date' => '2027-06-08',
            'end_date' => '2027-06-10',
            'reason' => 'This date range overlaps another request.',
        ])->assertSessionHasErrors('start_date');
    }

    public function test_sick_leave_accepts_and_secures_attachment(): void
    {
        Storage::fake('local');
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $sick = LeaveType::query()->where('code', 'SICK')->firstOrFail();

        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $sick->id,
            'start_date' => '2027-07-01',
            'end_date' => '2027-07-01',
            'reason' => 'Medical rest advised by physician.',
            'attachments' => [UploadedFile::fake()->create('medical-certificate.pdf', 100, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $attachment = LeaveRequest::query()->firstOrFail()->attachments()->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);
        $this->actingAs($employee)->get(route('leave-attachments.download', $attachment))->assertOk();
    }

    public function test_sick_leave_requires_an_attachment(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $sick = LeaveType::query()->where('code', 'SICK')->firstOrFail();

        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $sick->id,
            'start_date' => '2027-08-02',
            'end_date' => '2027-08-02',
            'reason' => 'Medical rest advised by physician.',
        ])->assertSessionHasErrors('attachments');
    }

    private function submitVacation(User $employee): LeaveRequest
    {
        $vacation = LeaveType::query()->where('code', 'VAC')->firstOrFail();
        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $vacation->id,
            'start_date' => '2027-06-07',
            'end_date' => '2027-06-09',
            'reason' => 'Scheduled family vacation leave.',
        ]);

        return LeaveRequest::query()->firstOrFail();
    }
}
