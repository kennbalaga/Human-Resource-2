<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use App\Services\ShiftSwapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Who may sign off a swap. It moves a shift on each side, so the reviewer must
 * supervise both people and be neither of them -- the same separation leave,
 * attendance and timesheet approval already hold.
 */
class ShiftSwapAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $hrManager;

    private User $head;

    private Department $otherUnit;

    private int $dayOffset = 30;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->hrManager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->head = User::query()->where('email', 'nursing.head@hrms.local')->firstOrFail();
        $this->otherUnit = Department::query()
            ->where('category', Department::CATEGORY_CLINICAL)
            ->whereKeyNot($this->head->employee->department_id)
            ->whereHas('positions')
            ->firstOrFail();
    }

    public function test_a_head_cannot_approve_a_swap_they_are_part_of(): void
    {
        $nurse = $this->nurse($this->head->employee->department);
        $swap = $this->acceptedSwap($this->head->employee, $nurse);

        $this->actingAs($this->head)->post(route('shift-swaps.approve', $swap))->assertSessionHasErrors('swap');

        $this->assertSame('pending_manager', $swap->refresh()->status);
    }

    public function test_a_head_cannot_approve_a_swap_that_moves_another_units_nurse(): void
    {
        $swap = $this->acceptedSwap($this->nurse($this->head->employee->department), $this->nurse($this->otherUnit));

        $this->actingAs($this->head)->post(route('shift-swaps.approve', $swap))->assertForbidden();

        $this->assertSame('pending_manager', $swap->refresh()->status);
    }

    public function test_hr_can_still_approve_a_swap_across_units(): void
    {
        $swap = $this->acceptedSwap($this->nurse($this->head->employee->department), $this->nurse($this->otherUnit));

        $this->actingAs($this->hrManager)->post(route('shift-swaps.approve', $swap))->assertSessionHasNoErrors();

        $this->assertSame('approved', $swap->refresh()->status);
    }

    public function test_a_head_cannot_cancel_another_units_swap(): void
    {
        $swap = $this->openSwap($this->nurse($this->otherUnit), $this->nurse($this->otherUnit));

        $this->actingAs($this->head)->post(route('shift-swaps.cancel', $swap))->assertForbidden();

        $this->assertSame('pending_target', $swap->refresh()->status);
    }

    public function test_a_head_can_still_cancel_a_swap_in_their_own_unit(): void
    {
        $unit = $this->head->employee->department;
        $swap = $this->openSwap($this->nurse($unit), $this->nurse($unit));

        $this->actingAs($this->head)->post(route('shift-swaps.cancel', $swap))->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $swap->refresh()->status);
    }

    private function nurse(Department $department): Employee
    {
        $user = User::query()->create([
            'name' => 'Swap Nurse',
            'email' => 'swap.'.Str::lower(Str::random(10)).'@hrms.test',
            'password' => 'Password-123456!',
            'is_active' => true,
        ]);
        $user->roles()->sync([Role::query()->where('slug', 'employee')->value('id')]);

        return Employee::query()->create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'position_id' => Position::query()->where('department_id', $department->id)->firstOrFail()->id,
            'employee_number' => 'SWP-'.Str::random(8),
            'first_name' => 'Swap',
            'last_name' => 'Nurse',
            'employment_status' => 'active',
            'hire_date' => '2024-01-01',
        ]);
    }

    private function openSwap(Employee $requester, Employee $target): ShiftSwapRequest
    {
        $shifts = Shift::query()->where('is_active', true)->orderBy('id')->limit(2)->get();

        return app(ShiftSwapService::class)->create($requester, [
            'requester_assignment_id' => $this->assign($requester, $shifts[0], $this->dayOffset)->id,
            'target_assignment_id' => $this->assign($target, $shifts[1], $this->dayOffset + 3)->id,
            'reason' => 'Covering a family commitment.',
        ]);
    }

    private function acceptedSwap(Employee $requester, Employee $target): ShiftSwapRequest
    {
        $swap = $this->openSwap($requester, $target);

        return app(ShiftSwapService::class)->respond($swap, $target, true, null);
    }

    private function assign(Employee $employee, Shift $shift, int $daysAhead): ScheduleAssignment
    {
        return RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'work_date' => now(config('schedule.timezone'))->addDays($daysAhead)->toDateString(),
            'status' => 'scheduled',
            'created_by' => $this->hrManager->id,
        ]));
    }
}
