<?php

namespace Tests\Feature\Schedule;

use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Who gets told a swap is waiting on them.
 *
 * A swap reaches a manager the moment the colleague accepts it, and until now
 * nothing said so: the requester was told their request had gone to their
 * manager, and the manager found out by opening a page they had no particular
 * reason to open. The request sat in a queue nobody had been sent to.
 *
 * The people told are the people who could actually answer it -- HR across the
 * hospital, and the head of the unit the request came from. A system
 * administrator is not one of them: the role is read-only, so the approval it
 * would be summoned to is one it cannot give.
 */
class ShiftSwapReviewerNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECT = 'A shift swap needs your approval';

    private Employee $requester;

    private Employee $colleague;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Notification::fake();

        $this->requester = User::query()->where('email', 'luz.santos@hrms.local')->firstOrFail()->employee;

        // Explicitly not the unit's head, who also works in this department:
        // these tests turn on whether the head is told to review a swap, which
        // says nothing if the head is one of the two people in it.
        $this->colleague = Employee::query()
            ->where('department_id', $this->requester->department_id)
            ->whereKeyNot($this->requester->id)
            ->whereKeyNot($this->user('nursing.head@hrms.local')->employee->id)
            ->firstOrFail();

        $this->shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function wasTold(User $user): bool
    {
        return $user->notifications()->get()
            ->contains(fn ($notification): bool => ($notification->data['title'] ?? null) === self::SUBJECT);
    }

    private function assignmentFor(Employee $employee, int $daysFromToday): ScheduleAssignment
    {
        return RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $this->shift->id,
            'work_date' => now(config('schedule.timezone'))->addDays($daysFromToday)->toDateString(),
            'status' => 'scheduled',
            'created_by' => $this->user('hr.manager@hrms.local')->id,
        ]));
    }

    /** Accepts the request as the colleague, which is what sends it to a manager. */
    private function acceptedSwap(): ShiftSwapRequest
    {
        $swap = ShiftSwapRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'requester_employee_id' => $this->requester->id,
            'requester_assignment_id' => $this->assignmentFor($this->requester, 5)->id,
            'target_employee_id' => $this->colleague->id,
            'target_assignment_id' => $this->assignmentFor($this->colleague, 7)->id,
            'reason' => 'Family emergency this week.',
            'status' => 'pending_target',
        ]);

        $this->actingAs($this->colleague->user)
            ->post(route('shift-swaps.respond', $swap), ['accept' => 1])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return $swap->refresh();
    }

    public function test_accepting_a_swap_tells_hr_it_needs_approval(): void
    {
        $swap = $this->acceptedSwap();

        $this->assertSame('pending_manager', $swap->status);
        $this->assertTrue($this->wasTold($this->user('hr.manager@hrms.local')));
    }

    public function test_the_head_of_the_unit_the_request_came_from_is_told(): void
    {
        $this->acceptedSwap();

        $this->assertTrue($this->wasTold($this->user('nursing.head@hrms.local')));
    }

    /**
     * Read-only by role, so the approval it would be called to is one it cannot
     * give. Being told would be an instruction to do something impossible.
     */
    public function test_a_read_only_system_administrator_is_not_told(): void
    {
        $this->acceptedSwap();

        $this->assertFalse($this->wasTold($this->user('admin@hrms.local')));
    }

    public function test_a_colleague_with_no_supervisory_role_is_not_told(): void
    {
        $this->acceptedSwap();

        $this->assertFalse($this->wasTold($this->user('employee@hrms.local')));
    }

    /**
     * A head who runs another unit cannot approve this one, and an approval
     * queue that fills with other departments' requests stops being a queue.
     */
    public function test_a_head_of_another_unit_is_not_told(): void
    {
        $otherHead = $this->user('nursing.head@hrms.local')->employee;
        $otherHead->department()->associate($this->user('employee@hrms.local')->employee->department_id)->save();

        $this->acceptedSwap();

        $this->assertFalse($this->wasTold($this->user('nursing.head@hrms.local')));
    }

    public function test_declining_tells_no_manager_anything(): void
    {
        $swap = ShiftSwapRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'requester_employee_id' => $this->requester->id,
            'requester_assignment_id' => $this->assignmentFor($this->requester, 5)->id,
            'target_employee_id' => $this->colleague->id,
            'target_assignment_id' => $this->assignmentFor($this->colleague, 7)->id,
            'reason' => 'Family emergency this week.',
            'status' => 'pending_target',
        ]);

        $this->actingAs($this->colleague->user)
            ->post(route('shift-swaps.respond', $swap), ['accept' => 0])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('declined_by_target', $swap->refresh()->status);
        $this->assertFalse($this->wasTold($this->user('hr.manager@hrms.local')));
    }

    /**
     * "A request needs your review" about your own swap reads as a mistake. HR
     * is org-wide, so leaving the two parties out never leaves nobody.
     */
    public function test_a_manager_who_is_half_of_the_trade_is_not_told_to_review_it(): void
    {
        $head = $this->user('nursing.head@hrms.local');
        $swap = ShiftSwapRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'requester_employee_id' => $head->employee->id,
            'requester_assignment_id' => $this->assignmentFor($head->employee, 5)->id,
            'target_employee_id' => $this->colleague->id,
            'target_assignment_id' => $this->assignmentFor($this->colleague, 7)->id,
            'reason' => 'Covering a family commitment next week.',
            'status' => 'pending_target',
        ]);

        $this->actingAs($this->colleague->user)
            ->post(route('shift-swaps.respond', $swap), ['accept' => 1])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->wasTold($head));
        $this->assertTrue($this->wasTold($this->user('hr.manager@hrms.local')));
    }
}
