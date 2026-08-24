<?php

namespace Tests\Unit\Scheduling;

use App\Exceptions\UnauthorisedRosterWrite;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RosterWriteContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_a_bare_create_outside_allow_throws(): void
    {
        $this->expectException(UnauthorisedRosterWrite::class);

        ScheduleAssignment::query()->create([
            'employee_id' => $this->employee()->id,
            'shift_id' => $this->shift()->id,
            'work_date' => '2026-09-01',
            'status' => 'scheduled',
            'created_by' => $this->actor()->id,
        ]);
    }

    public function test_a_bare_update_outside_allow_throws(): void
    {
        $assignment = $this->createAssignmentDirectly();

        $this->expectException(UnauthorisedRosterWrite::class);

        $assignment->update(['notes' => 'Should not be allowed.']);
    }

    public function test_a_bare_delete_outside_allow_throws(): void
    {
        $assignment = $this->createAssignmentDirectly();

        $this->expectException(UnauthorisedRosterWrite::class);

        $assignment->delete();
    }

    public function test_writes_succeed_inside_allow(): void
    {
        $employee = $this->employee();
        $shift = $this->shift();
        $actor = $this->actor();

        $assignment = RosterWriteContext::allow($actor, fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2026-09-02',
            'status' => 'scheduled',
            'created_by' => $actor->id,
        ]));

        $this->assertNotNull($assignment->id);

        RosterWriteContext::allow($actor, fn () => $assignment->update(['notes' => 'Allowed.']));
        $this->assertSame('Allowed.', $assignment->fresh()->notes);

        RosterWriteContext::allow($actor, fn () => $assignment->delete());
        $this->assertNull(ScheduleAssignment::find($assignment->id));
    }

    public function test_context_reentry_does_not_leave_it_open_after_an_exception(): void
    {
        $actor = $this->actor();

        try {
            RosterWriteContext::allow($actor, function () {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse(RosterWriteContext::isOpen());

        $this->expectException(UnauthorisedRosterWrite::class);
        ScheduleAssignment::query()->create([
            'employee_id' => $this->employee()->id,
            'shift_id' => $this->shift()->id,
            'work_date' => '2026-09-03',
            'status' => 'scheduled',
            'created_by' => $this->actor()->id,
        ]);
    }

    public function test_allow_unattended_is_refused_outside_local_and_testing(): void
    {
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->expectException(RuntimeException::class);
            RosterWriteContext::allowUnattended(fn () => null);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_allow_unattended_succeeds_in_testing(): void
    {
        $employee = $this->employee();
        $shift = $this->shift();

        $assignment = RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2026-09-04',
            'status' => 'scheduled',
            'created_by' => $this->actor()->id,
        ]));

        $this->assertNotNull($assignment->id);
    }

    public function test_current_actor_is_available_inside_allow_and_null_outside_it(): void
    {
        $actor = $this->actor();

        $this->assertNull(RosterWriteContext::currentActor());

        $observed = RosterWriteContext::allow($actor, fn () => RosterWriteContext::currentActor());

        $this->assertTrue($actor->is($observed));
        $this->assertNull(RosterWriteContext::currentActor());
    }

    public function test_is_unattended_toggles_around_allow_unattended(): void
    {
        $this->assertFalse(RosterWriteContext::isUnattended());

        $observed = RosterWriteContext::allowUnattended(fn () => RosterWriteContext::isUnattended());

        $this->assertTrue($observed);
        $this->assertFalse(RosterWriteContext::isUnattended());
    }

    private function createAssignmentDirectly(): ScheduleAssignment
    {
        return RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $this->employee()->id,
            'shift_id' => $this->shift()->id,
            'work_date' => '2026-09-05',
            'status' => 'scheduled',
            'created_by' => $this->actor()->id,
        ]));
    }

    private function employee(): Employee
    {
        return Employee::query()->where('employment_status', 'active')->orderBy('id')->firstOrFail();
    }

    private function shift(): Shift
    {
        return Shift::query()->create([
            'code' => 'RWC-'.Str::random(6),
            'name' => 'Roster Write Context Test',
            'start_time' => '08:00:00',
            'end_time' => '17:00:00',
            'break_minutes' => 60,
            'is_active' => true,
        ]);
    }

    private function actor(): User
    {
        return User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }
}
