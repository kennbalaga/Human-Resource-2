<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\Concerns\ConfirmsDownloadPassword;
use Tests\TestCase;

class LeaveWorkflowTest extends TestCase
{
    use ConfirmsDownloadPassword, RefreshDatabase;

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

    /**
     * Only a yearly allowance is credits anybody holds. Unpaid leave and
     * comp-off once carried an entitlement of 366 to stand for "no fixed cap",
     * and summing that alongside real credits had the page advertising 890
     * available days -- two placeholders contributing 732 of it. Per-occasion
     * statutory leave has to stay out of the figure for the same reason: 105
     * days of maternity leave is a ceiling for one childbirth, not credit.
     */
    public function test_available_credits_count_only_the_yearly_allowances(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $response = $this->actingAs($employee)->get(route('leaves.index'))->assertOk();
        $summary = $response->viewData('summary');

        // Read off what the page actually offered this employee, not every
        // active type: a designated allowance they do not hold -- solo parent
        // leave without the ID -- is not credit, and never reaches the tile.
        $allowances = $response->viewData('requestableTypes')
            ->filter(fn (LeaveType $type) => $type->isBalanceBacked());

        $this->assertEqualsWithDelta(
            $allowances->sum(fn (LeaveType $type) => (float) $type->annual_entitlement),
            $summary['available_days'],
            0.01,
        );

        // Guards the specific regression rather than just the arithmetic: a
        // single uncapped type creeping back in would clear this bar on its own.
        $this->assertLessThan(105, $summary['available_days']);
    }

    /**
     * The check that did not exist before: nothing stopped a male employee
     * filing the full 105 days of maternity leave, because eligibility was
     * never consulted -- an active type with a balance was the whole test.
     */
    public function test_sex_specific_statutory_leave_is_refused_to_ineligible_employees(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $employee->employee->update(['gender' => LeaveType::GENDER_MALE]);
        $maternity = LeaveType::query()->where('code', 'MATERNITY')->firstOrFail();

        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $maternity->id,
            'start_date' => '2027-06-07',
            'end_date' => '2027-06-11',
            'reason' => 'Requesting maternity leave for the delivery.',
        ])->assertSessionHasErrors('leave_type_id');

        $this->assertDatabaseMissing('leave_requests', ['leave_type_id' => $maternity->id]);
    }

    /**
     * A record HR has not completed is unknown, not a refusal, and the message
     * has to point somewhere that can fix it.
     */
    public function test_unrecorded_gender_blocks_the_request_and_says_why(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $employee->employee->update(['gender' => null]);
        $paternity = LeaveType::query()->where('code', 'PATERNITY')->firstOrFail();

        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $paternity->id,
            'start_date' => '2027-06-07',
            'end_date' => '2027-06-11',
            'reason' => 'Requesting paternity leave for the delivery.',
        ])->assertSessionHasErrors('leave_type_id');

        $this->assertStringContainsString('Ask HR', session('errors')->first('leave_type_id'));
    }

    /**
     * Maternity leave is 105 calendar days, not 105 working days, and the
     * 30-day global span cap must not stand in the way of the entitlement the
     * law grants. It is also per childbirth: no balance is drawn down, so the
     * entitlement is enforced as a ceiling on the one request instead.
     */
    public function test_maternity_leave_is_counted_in_calendar_days_up_to_its_ceiling(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $employee->employee->update(['gender' => LeaveType::GENDER_FEMALE]);
        $maternity = LeaveType::query()->where('code', 'MATERNITY')->firstOrFail();

        $start = Carbon::parse('2027-02-01');
        Storage::fake(config('workforce.attachment_disk'));

        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $maternity->id,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(104)->toDateString(),
            'reason' => 'Maternity leave for the expected delivery date.',
            'attachments' => [UploadedFile::fake()->create('medical-certificate.pdf', 100, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('leave_requests', [
            'leave_type_id' => $maternity->id,
            'requested_days' => 105,
        ]);

        $this->flushSession();

        // One day past the ceiling, which no balance would have caught.
        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $maternity->id,
            'start_date' => '2028-02-01',
            'end_date' => Carbon::parse('2028-02-01')->addDays(105)->toDateString(),
            'reason' => 'Maternity leave beyond the statutory ceiling.',
            'attachments' => [UploadedFile::fake()->create('medical-certificate.pdf', 100, 'application/pdf')],
        ])->assertSessionHasErrors('end_date');
    }

    /**
     * The bug the classification exists to kill: a per-occasion entitlement
     * seeded as a yearly allowance handed every employee a fresh 105 days each
     * January, whether or not anybody had given birth.
     */
    public function test_per_occasion_leave_grants_no_yearly_credits(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $this->actingAs($employee)->get(route('leaves.index'))->assertOk();

        $perEvent = LeaveType::query()->where('accrual_method', LeaveType::ACCRUAL_PER_EVENT)->pluck('id');

        $this->assertTrue($perEvent->isNotEmpty());
        $this->assertSame(0.0, (float) LeaveBalance::query()
            ->where('employee_id', $employee->employee->id)
            ->whereIn('leave_type_id', $perEvent)
            ->sum('entitled_days'));
    }

    /**
     * Solo parent leave is a real yearly allowance, so it grants credits like
     * any other -- but only to holders of a valid DSWD ID. Shown to the whole
     * workforce it advertised seven days that nobody without the ID could take.
     */
    public function test_designated_leave_is_hidden_from_staff_without_the_status(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $employee->employee->update(['solo_parent_id_number' => null, 'solo_parent_id_expires_on' => null]);

        $shown = $this->actingAs($employee)->get(route('leaves.index'))->assertOk()->viewData('requestableTypes');
        $this->assertNotContains('SOLO-PARENT', $shown->pluck('code')->all());

        // Still listed in the history filter, which spans the whole workforce.
        $filterable = $this->actingAs($employee)->get(route('leaves.index'))->assertOk()->viewData('types');
        $this->assertContains('SOLO-PARENT', $filterable->pluck('code')->all());
    }

    public function test_designated_leave_appears_once_hr_records_a_valid_id(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $employee->employee->update([
            'solo_parent_id_number' => 'SP-2026-0001',
            'solo_parent_id_expires_on' => now()->addYear()->toDateString(),
        ]);

        $shown = $this->actingAs($employee)->get(route('leaves.index'))->assertOk()->viewData('requestableTypes');

        $this->assertContains('SOLO-PARENT', $shown->pluck('code')->all());
    }

    /**
     * A DSWD solo parent ID lapses. An expiry nobody revisits would keep
     * granting the leave forever, so the date is checked rather than presence.
     */
    public function test_a_lapsed_solo_parent_id_refuses_the_leave_and_says_when_it_expired(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $employee->employee->update([
            'solo_parent_id_number' => 'SP-2020-0001',
            'solo_parent_id_expires_on' => '2026-01-31',
        ]);
        $soloParent = LeaveType::query()->where('code', 'SOLO-PARENT')->firstOrFail();
        Storage::fake(config('workforce.attachment_disk'));

        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $soloParent->id,
            'start_date' => '2027-06-07',
            'end_date' => '2027-06-08',
            'reason' => 'Parental leave to attend to my child.',
            'attachments' => [UploadedFile::fake()->create('solo-parent-id.pdf', 100, 'application/pdf')],
        ])->assertSessionHasErrors('leave_type_id');

        $this->assertStringContainsString('expired on 31 Jan 2026', session('errors')->first('leave_type_id'));
        $this->assertDatabaseMissing('leave_requests', ['leave_type_id' => $soloParent->id]);
    }

    /**
     * The gate is enforced server-side, not merely hidden from the picker: a
     * posted id for a type the employee cannot hold has to be refused.
     */
    public function test_designated_leave_is_refused_even_when_the_type_id_is_posted_directly(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $employee->employee->update(['solo_parent_id_number' => null, 'solo_parent_id_expires_on' => null]);
        $soloParent = LeaveType::query()->where('code', 'SOLO-PARENT')->firstOrFail();

        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $soloParent->id,
            'start_date' => '2027-06-07',
            'end_date' => '2027-06-08',
            'reason' => 'Parental leave to attend to my child.',
        ])->assertSessionHasErrors('leave_type_id');

        $this->assertDatabaseMissing('leave_requests', ['leave_type_id' => $soloParent->id]);
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
        config(['workforce.attachment_disk' => 'database']);
        Storage::fake('local');
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $sick = LeaveType::query()->where('code', 'SICK')->firstOrFail();
        $certificate = UploadedFile::fake()->create('medical-certificate.pdf', 100, 'application/pdf');
        $contents = (string) file_get_contents($certificate->getRealPath());

        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $sick->id,
            'start_date' => '2027-07-01',
            'end_date' => '2027-07-01',
            'reason' => 'Medical rest advised by physician.',
            'attachments' => [$certificate],
        ])->assertSessionHasNoErrors();

        // Kept in the shared database, not on the computer that took the upload.
        $attachment = LeaveRequest::query()->firstOrFail()->attachments()->firstOrFail();
        $this->assertSame('database', $attachment->disk);
        Storage::disk('local')->assertMissing($attachment->path);

        $this->actingAs($employee)->get(route('leave-attachments.download', $attachment))
            ->assertOk()
            ->assertStreamedContent($contents);
    }

    /**
     * A host with an ephemeral filesystem loses every attachment on redeploy,
     * so the storage target has to be a deployment setting rather than a
     * hardcoded disk. Uses 's3' purely as a disk name that is not the default.
     */
    public function test_attachments_follow_the_configured_disk(): void
    {
        config()->set('workforce.attachment_disk', 's3');
        Storage::fake('local');
        Storage::fake('s3');
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $sick = LeaveType::query()->where('code', 'SICK')->firstOrFail();

        $this->actingAs($employee)->post('/leaves', [
            'leave_type_id' => $sick->id,
            'start_date' => '2027-07-05',
            'end_date' => '2027-07-05',
            'reason' => 'Medical rest advised by physician.',
            'attachments' => [UploadedFile::fake()->create('medical-certificate.pdf', 100, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $attachment = LeaveRequest::query()->firstOrFail()->attachments()->firstOrFail();
        $this->assertSame('s3', $attachment->disk);
        Storage::disk('s3')->assertExists($attachment->path);
        Storage::disk('local')->assertMissing($attachment->path);

        // The download reads the disk recorded on the row, so attachments
        // uploaded before a disk change stay reachable after one.
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
            'category' => 'company',
            'annual_entitlement' => 5,
            'accrual_method' => 'annual',
            'day_basis' => 'working',
            'max_carry_over' => 0,
            'requires_attachment' => '1',
            'min_service_months' => 0,
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('leave_types', [
            'code' => 'FAMILY-RESPITE',
            'name' => 'Family Respite Leave',
            'category' => 'company',
            'accrual_method' => 'annual',
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
        $this->assertDatabaseCount('leave_types', 14);
        $this->assertDatabaseHas('leave_types', ['code' => 'MATERNITY', 'name' => 'Maternity Leave', 'annual_entitlement' => 105]);
        $this->assertDatabaseHas('leave_types', ['code' => 'PATERNITY', 'name' => 'Paternity Leave', 'annual_entitlement' => 7]);
        $this->assertDatabaseHas('leave_types', ['code' => 'SPECIAL-PRIVILEGE', 'name' => 'Special Leave (Special Privilege Leave)']);
        $this->assertDatabaseHas('leave_types', ['code' => 'BEREAVEMENT', 'name' => 'Bereavement Leave']);
        $this->assertDatabaseHas('leave_types', ['code' => 'STUDY', 'name' => 'Study Leave']);
        $this->assertDatabaseHas('leave_types', ['code' => 'UNPAID', 'name' => 'Unpaid Leave (Leave Without Pay)']);
        $this->assertDatabaseHas('leave_types', ['code' => 'COMP-OFF', 'name' => 'Compensatory Leave (Comp-Off)']);
    }

    public function test_hr_managers_cannot_request_leave_for_themselves(): void
    {
        $vacation = LeaveType::query()->where('code', 'VAC')->firstOrFail();
        $reviewer = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($reviewer)->get('/leaves')->assertOk()->assertDontSee('Request leave');
        $this->actingAs($reviewer)->post('/leaves', [
            'leave_type_id' => $vacation->id,
            'start_date' => '2027-06-07',
            'end_date' => '2027-06-09',
            'reason' => 'Scheduled family vacation leave.',
        ])->assertForbidden();

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
