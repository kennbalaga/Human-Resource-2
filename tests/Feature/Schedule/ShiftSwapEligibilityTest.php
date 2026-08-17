<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Services\ShiftSwapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Shift swaps cover a clinical shift — a nurse cannot make the ward, a
 * colleague takes it. Administrative staff work fixed office hours with
 * nothing to trade, so the feature does not extend to them: not requesting,
 * not being asked, not even seeing the page, except as a manager reviewing
 * requests system-wide.
 */
class ShiftSwapEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Employee $clinicalEmployee;

    private Employee $clinicalColleague;

    private Employee $administrativeEmployee;

    private User $manager;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->clinicalEmployee = User::query()->where('email', 'luz.santos@hrms.local')->firstOrFail()->employee;
        $this->administrativeEmployee = User::query()->where('email', 'employee@hrms.local')->firstOrFail()->employee;
        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();

        // A second person in the same clinical department as $clinicalEmployee,
        // so there is someone eligible to trade shifts with.
        $this->clinicalColleague = Employee::query()
            ->where('department_id', $this->clinicalEmployee->department_id)
            ->whereKeyNot($this->clinicalEmployee->id)
            ->firstOrFail();
    }

    private function assignmentFor(Employee $employee, string $date): ScheduleAssignment
    {
        return ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $this->shift->id,
            'work_date' => $date,
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
        ]);
    }

    public function test_clinical_department_is_eligible(): void
    {
        $this->assertTrue($this->clinicalEmployee->canUseShiftSwaps());
    }

    public function test_administrative_department_is_not_eligible(): void
    {
        $this->assertFalse($this->administrativeEmployee->canUseShiftSwaps());
    }

    public function test_a_clinical_employee_can_open_the_page_and_request_a_swap(): void
    {
        $mine = $this->assignmentFor($this->clinicalEmployee, '2027-06-01');
        $theirs = $this->assignmentFor($this->clinicalColleague, '2027-06-02');

        $this->actingAs($this->clinicalEmployee->user)
            ->get(route('shift-swaps.index'))
            ->assertOk()
            ->assertSee('Request swap');

        $this->actingAs($this->clinicalEmployee->user)
            ->post(route('shift-swaps.store'), [
                'requester_assignment_id' => $mine->id,
                'target_assignment_id' => $theirs->id,
                'reason' => 'Family emergency this week.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('shift_swap_requests', [
            'requester_employee_id' => $this->clinicalEmployee->id,
            'target_employee_id' => $this->clinicalColleague->id,
        ]);
    }

    public function test_an_administrative_employee_cannot_open_the_shift_swaps_page(): void
    {
        $this->actingAs($this->administrativeEmployee->user)
            ->get(route('shift-swaps.index'))
            ->assertForbidden();
    }

    public function test_an_administrative_employee_cannot_request_a_swap(): void
    {
        $administrativeColleague = Employee::query()
            ->where('department_id', $this->administrativeEmployee->department_id)
            ->whereKeyNot($this->administrativeEmployee->id)
            ->firstOrFail();

        $mine = $this->assignmentFor($this->administrativeEmployee, '2027-06-01');
        $theirs = $this->assignmentFor($administrativeColleague, '2027-06-02');

        $this->actingAs($this->administrativeEmployee->user)
            ->post(route('shift-swaps.store'), [
                'requester_assignment_id' => $mine->id,
                'target_assignment_id' => $theirs->id,
                'reason' => 'Testing an administrative swap attempt.',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('shift_swap_requests', [
            'requester_employee_id' => $this->administrativeEmployee->id,
        ]);
    }

    /**
     * The UI only ever offers colleagues from the requester's own department,
     * but the store endpoint takes a bare assignment id — this proves the
     * service itself refuses an administrative target even if one were sent
     * directly.
     */
    public function test_the_service_refuses_an_administrative_employee_as_the_swap_target(): void
    {
        $mine = $this->assignmentFor($this->clinicalEmployee, '2027-06-01');
        $administrativeTarget = $this->assignmentFor($this->administrativeEmployee, '2027-06-02');

        $this->expectException(ValidationException::class);

        app(ShiftSwapService::class)->create($this->clinicalEmployee, [
            'requester_assignment_id' => $mine->id,
            'target_assignment_id' => $administrativeTarget->id,
            'reason' => 'Crafted request naming an administrative colleague.',
        ]);
    }

    public function test_hr_manager_keeps_full_access_despite_being_administrative(): void
    {
        $this->assertFalse($this->manager->employee->canUseShiftSwaps());

        // Built directly rather than through the clinical employee's own HTTP
        // request: this app logs a session out the moment the authenticated
        // user changes mid-test (authenticateSessions(), a real production
        // protection), so a fixture row is the way to hand the manager an
        // existing request to review without switching identities.
        $mine = $this->assignmentFor($this->clinicalEmployee, '2027-06-01');
        $theirs = $this->assignmentFor($this->clinicalColleague, '2027-06-02');
        $swap = ShiftSwapRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'requester_employee_id' => $this->clinicalEmployee->id,
            'requester_assignment_id' => $mine->id,
            'target_employee_id' => $this->clinicalColleague->id,
            'target_assignment_id' => $theirs->id,
            'reason' => 'Family emergency this week.',
            'status' => 'pending_manager',
            'target_responded_at' => now(),
        ]);

        // The manager can still see and act on the request even though their
        // own employee record is administrative.
        $this->actingAs($this->manager)
            ->get(route('shift-swaps.index'))
            ->assertOk()
            ->assertSee($this->clinicalEmployee->full_name)
            // Reviewing others' requests is not the same as personal
            // self-service, so the manager's own "Request swap" stays hidden.
            ->assertDontSee('Request swap');

        $this->actingAs($this->manager)
            ->post(route('shift-swaps.approve', $swap))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_a_manager_cannot_request_a_swap_for_their_own_administrative_self(): void
    {
        $administrativeColleague = Employee::query()
            ->where('department_id', $this->manager->employee->department_id)
            ->whereKeyNot($this->manager->employee->id)
            ->firstOrFail();

        $mine = $this->assignmentFor($this->manager->employee, '2027-06-01');
        $theirs = $this->assignmentFor($administrativeColleague, '2027-06-02');

        $this->actingAs($this->manager)
            ->post(route('shift-swaps.store'), [
                'requester_assignment_id' => $mine->id,
                'target_assignment_id' => $theirs->id,
                'reason' => 'Manager attempting a personal admin swap.',
            ])
            ->assertForbidden();
    }

    public function test_the_sidebar_link_is_shown_to_clinical_staff(): void
    {
        $this->actingAs($this->clinicalEmployee->user)->get(route('dashboard'))->assertSee('Shift Swaps');
    }

    public function test_the_sidebar_link_is_shown_to_a_manager(): void
    {
        $this->actingAs($this->manager)->get(route('dashboard'))->assertSee('Shift Swaps');
    }

    public function test_the_sidebar_link_is_hidden_from_administrative_staff(): void
    {
        $this->actingAs($this->administrativeEmployee->user)->get(route('dashboard'))->assertDontSee('Shift Swaps');
    }

    public function test_a_support_category_department_is_also_not_eligible(): void
    {
        $support = Department::query()->create([
            'code' => 'SEC-TEST',
            'name' => 'Security Services',
            'category' => Department::CATEGORY_SUPPORT,
            'is_active' => true,
        ]);
        $this->administrativeEmployee->department()->associate($support)->save();

        $this->assertFalse($this->administrativeEmployee->refresh()->canUseShiftSwaps());
    }
}
