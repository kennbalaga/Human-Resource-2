<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RosterDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RosterDraftTest extends TestCase
{
    use RefreshDatabase;

    private Department $ward;

    private Position $staffPosition;

    private Position $chargePosition;

    private Shift $morning;

    private Shift $night;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Notification::fake();

        $this->morning = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();
        $this->night = Shift::query()->where('code', 'NIGHT-2200')->firstOrFail();

        $this->ward = Department::query()->create([
            'code' => 'RD-WARD',
            'name' => 'Roster Ward',
            'category' => Department::CATEGORY_CLINICAL,
            'bed_capacity' => 24,
            'nurse_patient_ratio' => 12,
            'is_active' => true,
        ]);

        $this->staffPosition = Position::query()->create([
            'department_id' => $this->ward->id,
            'code' => 'RD-STAFF',
            'title' => 'Roster Staff Nurse',
            'seniority_rank' => 1,
            'is_active' => true,
        ]);

        $this->chargePosition = Position::query()->create([
            'department_id' => $this->ward->id,
            'code' => 'RD-CHARGE',
            'title' => 'Roster Charge Nurse',
            'seniority_rank' => 3,
            'is_active' => true,
        ]);
    }

    public function test_it_groups_the_roster_by_date_and_shift(): void
    {
        $a = $this->employee('RD-0001', $this->staffPosition);
        $b = $this->employee('RD-0002', $this->chargePosition);

        $evaluation = app(RosterDraftService::class)->evaluate($this->ward, collect([
            $this->entry($a, $this->morning, '2027-04-05'),
            $this->entry($b, $this->morning, '2027-04-05'),
        ]), '2027-04-05', '2027-04-05');

        $this->assertCount(1, $evaluation['days']);
        $day = $evaluation['days'][0];
        $this->assertSame('Monday', $day['weekday']);

        $morningRow = collect($day['shifts'])->firstWhere('shift_id', $this->morning->id);
        $this->assertSame(2, $morningRow['count']);
        $this->assertSame(2, $morningRow['required']);
        $this->assertSame(1, $morningRow['senior_count']);
        $this->assertTrue($morningRow['meets_requirement']);

        // Everyone on the shift is listed, which is what the old flat table hid.
        $this->assertEqualsCanonicalizing(
            [$a->full_name, $b->full_name],
            collect($morningRow['assigned'])->pluck('name')->all(),
        );
    }

    public function test_a_shift_nobody_was_placed_on_still_shows_its_requirement(): void
    {
        $a = $this->employee('RD-0003', $this->staffPosition);

        $evaluation = app(RosterDraftService::class)->evaluate($this->ward, collect([
            $this->entry($a, $this->morning, '2027-04-05'),
        ]), '2027-04-05', '2027-04-05');

        $nightRow = collect($evaluation['days'][0]['shifts'])->firstWhere('shift_id', $this->night->id);

        $this->assertNotNull($nightRow);
        $this->assertSame(0, $nightRow['count']);
        $this->assertSame(2, $nightRow['required']);
        $this->assertFalse($nightRow['meets_requirement']);
    }

    public function test_it_reports_an_employee_on_approved_leave(): void
    {
        $onLeave = $this->employee('RD-0004', $this->staffPosition);
        $type = LeaveType::query()->firstOrFail();

        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $onLeave->id,
            'leave_type_id' => $type->id,
            'start_date' => '2027-04-05',
            'end_date' => '2027-04-06',
            'requested_days' => 2,
            'reason' => 'Approved absence',
            'status' => 'approved',
        ]);

        $evaluation = app(RosterDraftService::class)->evaluate($this->ward, collect([
            $this->entry($onLeave, $this->morning, '2027-04-05'),
        ]), '2027-04-05', '2027-04-05');

        $this->assertCount(1, $evaluation['issues']);
        $this->assertSame('Approved leave', $evaluation['issues'][0]['reason']);

        // A blocked entry must not be counted towards coverage.
        $morningRow = collect($evaluation['days'][0]['shifts'])->firstWhere('shift_id', $this->morning->id);
        $this->assertSame(0, $morningRow['count']);
    }

    public function test_it_publishes_the_roster_it_was_given_rather_than_regenerating_one(): void
    {
        $a = $this->employee('RD-0005', $this->staffPosition);
        $b = $this->employee('RD-0006', $this->chargePosition);
        $c = $this->employee('RD-0006B', $this->staffPosition);

        // Deliberately lopsided: a generator would never produce this, so seeing
        // it survive proves the reviewed roster is what gets written. The day
        // off sits on the same date as the shift entries — Tier A's coverage
        // gate below grades every relevant shift on every date the roster
        // touches, so a day off on a date nothing else covers would trip it
        // for reasons unrelated to what this test is actually about.
        $entries = collect([
            $this->entry($a, $this->night, '2027-04-05'),
            $this->entry($b, $this->night, '2027-04-05'),
            ['employee_id' => $c->id, 'shift_id' => null, 'work_date' => '2027-04-05'],
        ]);

        $result = app(RosterDraftService::class)->publish(
            $this->ward,
            $entries,
            User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'hr-manager'))->firstOrFail(),
            null,
            null,
            // Scoped to the shift this roster actually covers, the way the
            // real endpoint always does via the request's shift_ids — every
            // other active shift in the system would otherwise also be
            // graded for coverage on this date and trip the Tier A gate below.
            ['shift_ids' => [$this->night->id]],
        );

        $this->assertSame(2, $result['assignments']->count());
        $this->assertSame(1, $result['day_offs']->count());

        $this->assertDatabaseHas('schedule_assignments', [
            'employee_id' => $a->id,
            'shift_id' => $this->night->id,
            'work_date' => '2027-04-05',
        ]);
        $this->assertDatabaseHas('schedule_day_offs', [
            'employee_id' => $c->id,
            'work_date' => '2027-04-05 00:00:00',
        ]);
        $this->assertDatabaseMissing('schedule_assignments', [
            'employee_id' => $a->id,
            'shift_id' => $this->morning->id,
        ]);
    }

    public function test_publishing_leaves_out_an_entry_that_cannot_be_scheduled(): void
    {
        $employee = $this->employee('RD-0007', $this->staffPosition);
        $second = $this->employee('RD-0007B', $this->chargePosition);
        $third = $this->employee('RD-0007D', $this->staffPosition);
        $type = LeaveType::query()->firstOrFail();

        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2027-04-05',
            'end_date' => '2027-04-05',
            'requested_days' => 1,
            'reason' => 'Approved absence',
            'status' => 'approved',
        ]);

        // Two more, unblocked entries keep this shift at its required minimum
        // (2, from the ward's bed capacity / nurse-patient ratio) despite the
        // leave conflict dropping one of the three, so the roster-wide Tier A
        // coverage gate stays out of this test's way and the leave conflict
        // is what's actually exercised: that one unschedulable entry is
        // skipped rather than failing the whole publish.
        $result = app(RosterDraftService::class)->publish(
            $this->ward,
            collect([
                $this->entry($employee, $this->morning, '2027-04-05'),
                $this->entry($second, $this->morning, '2027-04-05'),
                $this->entry($third, $this->morning, '2027-04-05'),
            ]),
            User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'hr-manager'))->firstOrFail(),
            null,
            null,
            ['shift_ids' => [$this->morning->id]],
        );

        $this->assertSame(2, $result['assignments']->count());
        $this->assertSame(1, $result['skipped']->count());
        $this->assertSame(0, ScheduleAssignment::query()->where('employee_id', $employee->id)->count());
    }

    public function test_publishing_refuses_a_roster_that_leaves_a_shift_below_its_required_minimum(): void
    {
        $employee = $this->employee('RD-0007C', $this->staffPosition);
        $type = LeaveType::query()->firstOrFail();

        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => '2027-04-05',
            'end_date' => '2027-04-05',
            'requested_days' => 1,
            'reason' => 'Approved absence',
            'status' => 'approved',
        ]);

        // Tier A is a hard, no-override gate: a roster that leaves a shift
        // below its required minimum coverage is refused regardless of why —
        // even when the shortfall is a legitimate leave conflict rather than
        // an oversight, the reviewer must staff around it before publishing.
        try {
            app(RosterDraftService::class)->publish(
                $this->ward,
                collect([$this->entry($employee, $this->morning, '2027-04-05')]),
                User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'hr-manager'))->firstOrFail(),
                null,
                null,
                ['shift_ids' => [$this->morning->id]],
            );
            $this->fail('Publishing a roster short of its required minimum staff should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shifts_short', $exception->errors());
        }

        $this->assertSame(0, ScheduleAssignment::query()->where('employee_id', $employee->id)->count());
    }

    public function test_the_endpoint_publishes_an_edited_roster(): void
    {
        $manager = User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'hr-manager'))->firstOrFail();
        $employee = $this->employee('RD-0008', $this->chargePosition);
        // The ward's derived minimum is 2 (from its bed capacity / ratio), so
        // a second entry is needed to clear the Tier A coverage gate.
        $second = $this->employee('RD-0008B', $this->staffPosition);

        $response = $this->actingAs($manager)->post(route('schedules.roster.publish'), [
            'department_id' => $this->ward->id,
            'start_date' => '2027-04-05',
            'end_date' => '2027-04-05',
            // The real roster board always scopes this to the shifts it
            // shows, the same way it's passed directly to the service
            // elsewhere in this file — without it every other active shift
            // in the system is graded for coverage on this date too.
            'shift_ids' => [$this->morning->id],
            'entries' => [
                ['employee_id' => $employee->id, 'shift_id' => $this->morning->id, 'work_date' => '2027-04-05'],
                ['employee_id' => $second->id, 'shift_id' => $this->morning->id, 'work_date' => '2027-04-05'],
            ],
        ])->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('schedule_assignments', [
            'employee_id' => $employee->id,
            'shift_id' => $this->morning->id,
        ]);
    }

    public function test_the_roster_endpoints_refuse_an_employee_from_another_department(): void
    {
        $manager = User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'hr-manager'))->firstOrFail();
        $outsider = Employee::query()->where('employee_number', 'HR-OFFICER-2026-0001')->firstOrFail();

        $this->actingAs($manager)->post(route('schedules.roster.publish'), [
            'department_id' => $this->ward->id,
            'start_date' => '2027-04-05',
            'end_date' => '2027-04-05',
            'entries' => [
                ['employee_id' => $outsider->id, 'shift_id' => $this->morning->id, 'work_date' => '2027-04-05'],
            ],
        ])->assertSessionHasErrors('entries.0.employee_id');
    }

    public function test_a_standard_employee_cannot_publish_a_roster(): void
    {
        $employee = User::query()
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head']))
            ->firstOrFail();

        $this->actingAs($employee)->post(route('schedules.roster.publish'), [
            'department_id' => $this->ward->id,
            'start_date' => '2027-04-05',
            'end_date' => '2027-04-05',
            'entries' => [],
        ])->assertForbidden();
    }

    /**
     * @return array{employee_id: int, shift_id: int, work_date: string}
     */
    private function entry(Employee $employee, Shift $shift, string $date): array
    {
        return ['employee_id' => $employee->id, 'shift_id' => $shift->id, 'work_date' => $date];
    }

    private function employee(string $number, Position $position): Employee
    {
        return Employee::query()->create([
            'department_id' => $this->ward->id,
            'position_id' => $position->id,
            'employee_number' => $number,
            'first_name' => 'Roster',
            'last_name' => 'Nurse '.$number,
            'employment_status' => 'active',
            'hire_date' => '2024-01-01',
        ]);
    }
}
