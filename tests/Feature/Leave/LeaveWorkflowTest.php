<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
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

    public function test_hr_manager_can_manually_add_a_leave_type(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        app(EnableTwoFactorAuthentication::class)($manager);
        $manager->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->actingAs($manager)->post(route('leave-types.store'), [
            'code' => 'FAMILY-RESPITE',
            'name' => 'Family Respite Leave',
            'color' => '#5B21B6',
            'annual_entitlement' => 5,
            'max_carry_over' => 0,
            'requires_attachment' => '1',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('leave_types', [
            'code' => 'FAMILY-RESPITE',
            'name' => 'Family Respite Leave',
            'requires_attachment' => true,
            'is_active' => true,
        ]);

    }

    public function test_regular_employee_cannot_add_a_leave_type(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($employee)->post(route('leave-types.store'), [
            'code' => 'BEREAVEMENT',
            'name' => 'Bereavement Leave',
            'color' => '#5B21B6',
            'annual_entitlement' => 5,
            'max_carry_over' => 0,
            'is_active' => '1',
        ])->assertForbidden();
    }

    public function test_requested_default_leave_types_are_available(): void
    {
        $this->assertDatabaseCount('leave_types', 10);
        $this->assertDatabaseHas('leave_types', ['code' => 'MATERNITY', 'name' => 'Maternity Leave', 'annual_entitlement' => 105]);
        $this->assertDatabaseHas('leave_types', ['code' => 'PATERNITY', 'name' => 'Paternity Leave', 'annual_entitlement' => 7]);
        $this->assertDatabaseHas('leave_types', ['code' => 'SPECIAL-PRIVILEGE', 'name' => 'Special Leave (Special Privilege Leave)']);
        $this->assertDatabaseHas('leave_types', ['code' => 'BEREAVEMENT', 'name' => 'Bereavement Leave']);
        $this->assertDatabaseHas('leave_types', ['code' => 'STUDY', 'name' => 'Study Leave']);
        $this->assertDatabaseHas('leave_types', ['code' => 'UNPAID', 'name' => 'Unpaid Leave (Leave Without Pay)']);
        $this->assertDatabaseHas('leave_types', ['code' => 'COMP-OFF', 'name' => 'Compensatory Leave (Comp-Off)']);
    }

    public function test_administrators_and_hr_managers_cannot_request_leave_for_themselves(): void
    {
        $vacation = LeaveType::query()->where('code', 'VAC')->firstOrFail();

        foreach (['admin@hrms.local', 'hr.manager@hrms.local'] as $email) {
            $reviewer = User::query()->where('email', $email)->firstOrFail();
            $this->flushSession();

            $this->actingAs($reviewer)->get('/leaves')->assertOk()->assertDontSee('Request leave');
            $this->actingAs($reviewer)->post('/leaves', [
                'leave_type_id' => $vacation->id,
                'start_date' => '2027-06-07',
                'end_date' => '2027-06-09',
                'reason' => 'Scheduled family vacation leave.',
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_department_heads_keep_leave_self_service(): void
    {
        $head = User::query()->where('email', 'nursing.head@hrms.local')->firstOrFail();

        $this->actingAs($head)->get('/leaves')->assertOk()->assertSee('Request leave');
        $this->submitVacation($head);

        $this->assertDatabaseHas('leave_requests', ['employee_id' => $head->employee->id, 'status' => 'pending']);
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
