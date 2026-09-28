<?php

namespace Tests\Feature\Schedule;

use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use App\Services\ShiftSwapService;
use App\Services\SidebarBadgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A swap asks about a shift that is coming. Once that shift has been worked the
 * question is closed, whoever was sitting on it.
 *
 * It was not closed before. Approval refuses a shift already worked, but
 * nothing else did: a request could go unanswered past its own date, be
 * accepted by the colleague weeks late, and land in the manager's queue as
 * pending for ever — counted on their sidebar badge, offering an Approve
 * button that could only ever refuse it. These tests hold the three places that
 * now end it: the colleague's answer, the manager's, and the nightly sweep.
 */
class ShiftSwapExpiryTest extends TestCase
{
    use RefreshDatabase;

    private Employee $requester;

    private Employee $colleague;

    private User $manager;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->requester = User::query()->where('email', 'luz.santos@hrms.local')->firstOrFail()->employee;
        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();

        // Not the unit's own head, who also works in this department: these
        // tests move the colleague into an administrative post, and doing that
        // to the head would quietly change who supervises the unit as well.
        $this->colleague = Employee::query()
            ->where('department_id', $this->requester->department_id)
            ->whereKeyNot($this->requester->id)
            ->whereKeyNot(User::query()->where('email', 'nursing.head@hrms.local')->firstOrFail()->employee->id)
            ->firstOrFail();
    }

    /** A department whose staff have fixed office hours and so no shift to trade. */
    private function administrativeDepartmentId(): int
    {
        return (int) User::query()->where('email', 'employee@hrms.local')->firstOrFail()->employee->department_id;
    }

    /** Dates are taken from the clock, so these tests do not rot on a fixed calendar. */
    private function date(int $daysFromToday): string
    {
        return now(config('schedule.timezone'))->addDays($daysFromToday)->toDateString();
    }

    private function assignmentFor(Employee $employee, string $date): ScheduleAssignment
    {
        return RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $this->shift->id,
            'work_date' => $date,
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
        ]));
    }

    private function swapRequest(string $requesterDate, string $targetDate, string $status = 'pending_target'): ShiftSwapRequest
    {
        return ShiftSwapRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'requester_employee_id' => $this->requester->id,
            'requester_assignment_id' => $this->assignmentFor($this->requester, $requesterDate)->id,
            'target_employee_id' => $this->colleague->id,
            'target_assignment_id' => $this->assignmentFor($this->colleague, $targetDate)->id,
            'reason' => 'Family emergency this week.',
            'status' => $status,
            'target_responded_at' => $status === 'pending_manager' ? now() : null,
        ]);
    }

    public function test_the_sweep_closes_a_request_whose_shift_has_been_worked(): void
    {
        $swap = $this->swapRequest($this->date(-5), $this->date(-3));

        $expired = app(ShiftSwapService::class)->expireStale();

        $this->assertCount(1, $expired);
        $this->assertSame('expired', $swap->refresh()->status);
        $this->assertNotNull($swap->expired_at);
        $this->assertSame('The shift was worked before anyone answered.', $swap->expired_reason);
    }

    /**
     * Eligibility follows the department, so a transfer to an administrative
     * post takes shift swaps away mid-request. The page the request lives on is
     * then hidden from them, and a request nobody can see is not one anybody
     * will answer -- it used to sit in the manager's queue for good.
     */
    public function test_the_sweep_closes_a_request_whose_colleague_no_longer_swaps_shifts(): void
    {
        $swap = $this->swapRequest($this->date(5), $this->date(7));

        $this->colleague->department()->associate($this->administrativeDepartmentId())->save();

        app(ShiftSwapService::class)->expireStale();

        $swap->refresh();
        $this->assertSame('expired', $swap->status);
        $this->assertStringContainsString($this->colleague->full_name, $swap->expired_reason);
    }

    public function test_the_sweep_closes_a_request_whose_requester_no_longer_swaps_shifts(): void
    {
        $swap = $this->swapRequest($this->date(5), $this->date(7));

        $this->requester->department()->associate($this->administrativeDepartmentId())->save();

        app(ShiftSwapService::class)->expireStale();

        $swap->refresh();
        $this->assertSame('expired', $swap->status);
        $this->assertStringContainsString($this->requester->full_name, $swap->expired_reason);
    }

    public function test_approving_a_request_whose_party_no_longer_swaps_shifts_closes_it(): void
    {
        $swap = $this->swapRequest($this->date(5), $this->date(7), 'pending_manager');

        $this->colleague->department()->associate($this->administrativeDepartmentId())->save();

        $this->expectException(ValidationException::class);

        try {
            app(ShiftSwapService::class)->approve($swap, $this->manager, null);
        } finally {
            $this->assertSame('expired', $swap->refresh()->status);
        }
    }

    /**
     * The sweep and the dry run must describe the same set, which is why they
     * now read it from the same place rather than each asking in their own way.
     */
    public function test_the_dry_run_lists_exactly_what_the_sweep_would_close(): void
    {
        $this->swapRequest($this->date(-5), $this->date(-3));
        $this->swapRequest($this->date(6), $this->date(8));
        $ineligible = $this->swapRequest($this->date(5), $this->date(7));
        $this->colleague->department()->associate($this->administrativeDepartmentId())->save();

        $service = app(ShiftSwapService::class);
        $previewed = $service->unanswerable()->modelKeys();

        $this->assertContains($ineligible->id, $previewed);
        $this->assertSame($previewed, $service->expireStale()->modelKeys());
    }

    /**
     * The earlier shift sets the deadline. Once one side has been worked there
     * is no trade left to make, however far off the other still is.
     */
    public function test_the_sweep_closes_a_request_where_only_one_side_has_passed(): void
    {
        $swap = $this->swapRequest($this->date(-1), $this->date(9));

        app(ShiftSwapService::class)->expireStale();

        $this->assertSame('expired', $swap->refresh()->status);
    }

    public function test_the_sweep_leaves_an_upcoming_request_alone(): void
    {
        $swap = $this->swapRequest($this->date(4), $this->date(6));

        $this->assertCount(0, app(ShiftSwapService::class)->expireStale());
        $this->assertSame('pending_target', $swap->refresh()->status);
    }

    /** Both sides keep the whole of the shift's own day, so today is never swept. */
    public function test_a_request_for_today_is_not_swept(): void
    {
        $swap = $this->swapRequest($this->date(0), $this->date(3));

        app(ShiftSwapService::class)->expireStale();

        $this->assertSame('pending_target', $swap->refresh()->status);
    }

    public function test_a_settled_request_is_never_reopened_or_expired(): void
    {
        $swap = $this->swapRequest($this->date(-5), $this->date(-3));
        $swap->update(['status' => 'declined_by_target']);

        app(ShiftSwapService::class)->expireStale();

        $this->assertSame('declined_by_target', $swap->refresh()->status);
    }

    /**
     * The hole this whole change closes: accepting is what puts a request in
     * front of a manager, so a late acceptance used to create a row that could
     * never be approved.
     */
    public function test_a_colleague_cannot_accept_a_swap_whose_shift_has_been_worked(): void
    {
        $swap = $this->swapRequest($this->date(-4), $this->date(-2));

        $this->actingAs($this->colleague->user)
            ->post(route('shift-swaps.respond', $swap), ['accept' => 1])
            ->assertRedirect()
            ->assertSessionHasErrors('swap');

        $swap->refresh();
        $this->assertSame('expired', $swap->status);
        $this->assertNotNull($swap->expired_at);
    }

    public function test_a_colleague_can_still_accept_an_upcoming_swap(): void
    {
        $swap = $this->swapRequest($this->date(5), $this->date(7));

        $this->actingAs($this->colleague->user)
            ->post(route('shift-swaps.respond', $swap), ['accept' => 1])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('pending_manager', $swap->refresh()->status);
    }

    /**
     * A reviewer who is told "no" on a row that then stays in their queue has
     * been told nothing useful, so approval closes it rather than refusing it.
     */
    public function test_approving_a_request_whose_shift_has_passed_closes_it(): void
    {
        $swap = $this->swapRequest($this->date(-6), $this->date(-2), 'pending_manager');

        $this->expectException(ValidationException::class);

        try {
            app(ShiftSwapService::class)->approve($swap, $this->manager, null);
        } finally {
            $this->assertSame('expired', $swap->refresh()->status);
        }
    }

    public function test_an_expired_request_stops_counting_against_the_reviewer(): void
    {
        $swap = $this->swapRequest($this->date(-6), $this->date(-2), 'pending_manager');

        $before = app(SidebarBadgeService::class)->forUser($this->manager)['swaps'];

        app(ShiftSwapService::class)->expireStale();

        $after = app(SidebarBadgeService::class)->forUser($this->manager)['swaps'];

        $this->assertSame($before - 1, $after);
        $this->assertSame('expired', $swap->refresh()->status);
    }

    /**
     * An expired request is an ending, so it must not go on reserving the
     * shifts it named — the requester has to be able to try again.
     */
    public function test_an_expired_request_does_not_block_a_fresh_one(): void
    {
        $mine = $this->assignmentFor($this->requester, $this->date(8));
        $theirs = $this->assignmentFor($this->colleague, $this->date(10));

        ShiftSwapRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'requester_employee_id' => $this->requester->id,
            'requester_assignment_id' => $mine->id,
            'target_employee_id' => $this->colleague->id,
            'target_assignment_id' => $theirs->id,
            'reason' => 'An earlier attempt that nobody answered.',
            'status' => 'expired',
            'expired_at' => now(),
        ]);

        $fresh = app(ShiftSwapService::class)->create($this->requester, [
            'requester_assignment_id' => $mine->id,
            'target_assignment_id' => $theirs->id,
            'reason' => 'Asking again now that the first request lapsed.',
        ]);

        $this->assertSame('pending_target', $fresh->status);
    }

    public function test_the_command_reports_what_it_closed(): void
    {
        $this->swapRequest($this->date(-5), $this->date(-3));

        $this->artisan('shift-swaps:expire --dry-run')
            ->expectsOutputToContain('Would expire: '.$this->requester->full_name)
            ->assertSuccessful();

        // A dry run must not have touched anything, so the real run still has
        // the same one to close.
        $this->artisan('shift-swaps:expire')
            ->expectsOutputToContain('1 request(s) expired.')
            ->assertSuccessful();
    }
}
