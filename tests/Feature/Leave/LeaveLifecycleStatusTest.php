<?php

namespace Tests\Feature\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Approved leave used to read "Approved" forever, so the leave list could not
 * answer either of the questions people arrive with -- who is out right now,
 * and whose leave is already behind them. A request now carries a phase derived
 * from its dates, and these tests pin the boundaries of that derivation.
 *
 * The phase is computed on read rather than stored, so every case here travels
 * the clock instead of waiting for a job to run.
 */
class LeaveLifecycleStatusTest extends TestCase
{
    use RefreshDatabase;

    /** A mid-year Wednesday, so past, present and future leave share one year. */
    private const TODAY = '2026-09-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow(self::TODAY.' 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_approved_leave_that_has_ended_reads_as_completed(): void
    {
        $leave = $this->approvedLeave('2026-08-01', '2026-08-06');

        $this->assertSame('completed', $leave->phase);
        $this->assertSame('completed', $leave->lifecycle_status);

        // The stored approval state is untouched: every query outside this
        // module still finds this leave by asking for status = approved.
        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id, 'status' => 'approved']);
    }

    public function test_approved_leave_covering_today_reads_as_ongoing(): void
    {
        $this->assertSame('ongoing', $this->approvedLeave('2026-09-07', '2026-09-11')->phase);

        // Inclusive at both ends: the first and last day of leave are days off.
        $this->assertSame('ongoing', $this->approvedLeave('2026-09-09', '2026-09-09')->phase);
    }

    public function test_approved_leave_that_has_not_started_reads_as_upcoming(): void
    {
        $this->assertSame('upcoming', $this->approvedLeave('2026-09-21', '2026-09-25')->phase);

        // The day before it starts is still upcoming, not ongoing.
        $this->assertSame('upcoming', $this->approvedLeave('2026-09-10', '2026-09-12')->phase);
    }

    public function test_unapproved_leave_has_no_phase_whatever_its_dates(): void
    {
        foreach (['pending', 'rejected', 'cancelled'] as $status) {
            $leave = $this->leave('2026-08-01', '2026-08-06', $status);

            $this->assertNull($leave->phase, "A {$status} request should have no phase.");
            $this->assertSame($status, $leave->lifecycle_status);
        }
    }

    /**
     * Asserted through the badge's tone rather than its wording: every phase
     * name also appears in the status filter's dropdown, so seeing the word
     * "Ongoing" on the page would prove nothing. `status-primary` is reachable
     * only from the badge, and only for leave being taken right now.
     */
    public function test_the_leave_list_badge_shows_the_phase_rather_than_a_stale_approval(): void
    {
        $this->approvedLeave('2026-09-07', '2026-09-11', 'Leave being taken this week.');

        $this->actingAs($this->employeeUser())
            ->get(route('leaves.index'))
            ->assertOk()
            ->assertSee('status-badge status-primary');
    }

    public function test_filtering_by_a_phase_narrows_to_leave_at_that_point(): void
    {
        $this->approvedLeave('2026-08-01', '2026-08-06', 'Leave already taken in August.');
        $this->approvedLeave('2026-09-07', '2026-09-11', 'Leave being taken this week.');
        $this->approvedLeave('2026-09-21', '2026-09-25', 'Leave booked for later this month.');

        $this->actingAs($this->employeeUser())
            ->get(route('leaves.index', ['status' => 'completed']))
            ->assertOk()
            ->assertSee('Leave already taken in August.')
            ->assertDontSee('Leave being taken this week.')
            ->assertDontSee('Leave booked for later this month.');

        $this->actingAs($this->employeeUser())
            ->get(route('leaves.index', ['status' => 'ongoing']))
            ->assertOk()
            ->assertSee('Leave being taken this week.')
            ->assertDontSee('Leave already taken in August.');

        // Asking for the approval state still returns all three phases: the
        // filter was widened, not repartitioned.
        $this->actingAs($this->employeeUser())
            ->get(route('leaves.index', ['status' => 'approved']))
            ->assertOk()
            ->assertSee('Leave already taken in August.')
            ->assertSee('Leave being taken this week.')
            ->assertSee('Leave booked for later this month.');
    }

    public function test_leave_that_has_already_been_taken_cannot_be_cancelled(): void
    {
        $leave = $this->approvedLeave('2026-08-01', '2026-08-06');
        $balance = $this->vacationBalance(['used_days' => 3]);

        $this->actingAs($this->employeeUser())
            ->post(route('leaves.cancel', $leave))
            ->assertSessionHasErrors('leave');

        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id, 'status' => 'approved']);

        // The credits are the point of the guard: the employee was away those
        // days, so they must not come back.
        $this->assertSame(3.0, (float) $balance->refresh()->used_days);
    }

    public function test_a_manager_cannot_cancel_leave_that_has_already_been_taken_either(): void
    {
        $leave = $this->approvedLeave('2026-08-01', '2026-08-06');
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)
            ->post(route('leaves.cancel', $leave))
            ->assertSessionHasErrors('leave');

        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id, 'status' => 'approved']);
    }

    /**
     * The boundary is drawn at the end of the leave, not its start. Somebody
     * who returns early still needs a way to give the remaining days back.
     */
    public function test_leave_being_taken_right_now_can_still_be_cancelled(): void
    {
        $leave = $this->approvedLeave('2026-09-07', '2026-09-11');
        $balance = $this->vacationBalance(['used_days' => 3]);

        $this->actingAs($this->employeeUser())
            ->post(route('leaves.cancel', $leave))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id, 'status' => 'cancelled']);
        $this->assertSame(2.0, (float) $balance->refresh()->used_days);
    }

    public function test_the_cancel_button_is_withdrawn_once_the_leave_has_been_taken(): void
    {
        $taken = $this->approvedLeave('2026-08-01', '2026-08-06');

        $this->actingAs($this->employeeUser())
            ->get(route('leaves.index'))
            ->assertOk()
            ->assertDontSee(route('leaves.cancel', $taken), false);

        $ongoing = $this->approvedLeave('2026-09-07', '2026-09-11');

        $this->actingAs($this->employeeUser())
            ->get(route('leaves.index'))
            ->assertOk()
            ->assertSee(route('leaves.cancel', $ongoing), false);
    }

    public function test_the_api_reports_the_phase_alongside_the_unchanged_status(): void
    {
        $this->approvedLeave('2026-08-01', '2026-08-06');
        Sanctum::actingAs($this->employeeUser(), ['workforce:read', 'leave:write']);

        $this->getJson('/api/v1/leaves')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'approved')
            ->assertJsonPath('data.0.phase', 'completed')
            ->assertJsonPath('data.0.lifecycle_status', 'completed');
    }

    private function employeeUser(): User
    {
        return User::query()->where('email', 'employee@hrms.local')->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    private function vacationBalance(array $attributes): LeaveBalance
    {
        return LeaveBalance::query()->updateOrCreate(
            [
                'employee_id' => $this->employeeUser()->employee->id,
                'leave_type_id' => LeaveType::query()->where('code', 'VAC')->firstOrFail()->id,
                'year' => Carbon::parse(self::TODAY)->year,
            ],
            $attributes + ['entitled_days' => 15, 'pending_days' => 0],
        );
    }

    private function approvedLeave(string $start, string $end, string $reason = 'Approved leave for lifecycle testing.'): LeaveRequest
    {
        return $this->leave($start, $end, 'approved', $reason);
    }

    /**
     * Rows are built directly rather than filed through LeaveService: the
     * service refuses leave that overlaps or that has no balance behind it, and
     * neither rule has anything to say about how a phase is derived.
     */
    private function leave(string $start, string $end, string $status, string $reason = 'Leave filed for lifecycle testing.'): LeaveRequest
    {
        $employee = Employee::query()->whereHas('user', fn ($query) => $query->where('email', 'employee@hrms.local'))->firstOrFail();

        return LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->where('code', 'VAC')->firstOrFail()->id,
            'start_date' => $start,
            'end_date' => $end,
            'requested_days' => 1,
            'reason' => $reason,
            'status' => $status,
        ]);
    }
}
