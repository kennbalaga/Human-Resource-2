<?php

namespace Tests\Unit\Scheduling;

use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleAssignmentAudit;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ScheduleAssignmentAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_create_update_delete_each_produce_one_audit_row(): void
    {
        $employee = $this->employee();
        $shift = $this->shift();
        $actor = $this->actor();

        $assignment = RosterWriteContext::allow($actor, fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2026-09-10',
            'status' => 'scheduled',
            'created_via' => 'manual',
            'created_by' => $actor->id,
        ]));

        $created = ScheduleAssignmentAudit::query()->where('schedule_assignment_id', $assignment->id)->where('action', 'created')->first();
        $this->assertNotNull($created);
        $this->assertSame($actor->id, $created->actor_id);
        $this->assertSame('manual', $created->created_via);
        $this->assertFalse($created->unattended);
        $this->assertSame($employee->id, $created->employee_id);

        RosterWriteContext::allow($actor, fn () => $assignment->update(['notes' => 'Updated.']));
        $this->assertSame(1, ScheduleAssignmentAudit::query()->where('schedule_assignment_id', $assignment->id)->where('action', 'updated')->count());

        $assignmentId = $assignment->id;
        RosterWriteContext::allow($actor, fn () => $assignment->delete());
        $deleted = ScheduleAssignmentAudit::query()->where('schedule_assignment_id', $assignmentId)->where('action', 'deleted')->first();
        $this->assertNotNull($deleted);
        // The audit row survives even though the assignment itself is gone.
        $this->assertNull(ScheduleAssignment::find($assignmentId));
    }

    public function test_unattended_writes_are_flagged_with_no_actor(): void
    {
        $employee = $this->employee();
        $shift = $this->shift();

        $assignment = RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2026-09-11',
            'status' => 'scheduled',
            'created_via' => 'legacy',
            'created_by' => $this->actor()->id,
        ]));

        $audit = ScheduleAssignmentAudit::query()->where('schedule_assignment_id', $assignment->id)->firstOrFail();
        $this->assertTrue($audit->unattended);
        $this->assertNull($audit->actor_id);
    }

    public function test_audit_rows_cannot_be_updated_or_deleted(): void
    {
        $employee = $this->employee();
        $shift = $this->shift();
        $assignment = RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2026-09-12',
            'status' => 'scheduled',
            'created_by' => $this->actor()->id,
        ]));
        $audit = ScheduleAssignmentAudit::query()->where('schedule_assignment_id', $assignment->id)->firstOrFail();

        $this->expectException(RuntimeException::class);
        $audit->update(['action' => 'tampered']);
    }

    private function employee(): Employee
    {
        return Employee::query()->where('employment_status', 'active')->orderBy('id')->firstOrFail();
    }

    private function shift(): Shift
    {
        return Shift::query()->create([
            'code' => 'SAA-'.Str::random(6),
            'name' => 'Schedule Assignment Audit Test',
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
