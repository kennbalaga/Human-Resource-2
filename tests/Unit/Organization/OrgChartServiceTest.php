<?php

namespace Tests\Unit\Organization;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Services\Organization\OrgChartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgChartServiceTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();

        $this->department = Department::query()->create([
            'code' => 'ORG',
            'name' => 'Org Chart Test Unit',
            'category' => Department::CATEGORY_ADMINISTRATIVE,
            'is_active' => true,
        ]);

        $this->position = Position::query()->create([
            'department_id' => $this->department->id,
            'code' => 'ORG-STAFF',
            'title' => 'Org Chart Test Staff',
            'seniority_rank' => 3,
            'is_active' => true,
        ]);
    }

    public function test_it_nests_reports_under_their_supervisor(): void
    {
        $head = $this->employee('ORG-001');
        $lead = $this->employee('ORG-002', $head);
        $this->employee('ORG-003', $lead);

        $chart = app(OrgChartService::class)->tree();

        $this->assertCount(1, $chart['roots']);
        $this->assertSame($head->id, $chart['roots'][0]['id']);
        $this->assertSame(1, $chart['roots'][0]['direct_reports']);

        $second = $chart['roots'][0]['reports'][0];
        $this->assertSame($lead->id, $second['id']);
        $this->assertCount(1, $second['reports']);
        $this->assertSame(3, $chart['placed']);
        $this->assertSame(0, $chart['unplaced']);
    }

    public function test_an_employee_without_a_supervisor_is_a_root(): void
    {
        $this->employee('ORG-001');
        $this->employee('ORG-002');

        $chart = app(OrgChartService::class)->tree();

        $this->assertCount(2, $chart['roots']);
        $this->assertSame(0, $chart['unplaced']);
    }

    /**
     * The supervisor still exists as a row but has left the hospital, so they
     * are filtered out of the chart. Their reports must surface as roots
     * rather than disappearing along with them.
     */
    public function test_an_employee_whose_supervisor_has_left_becomes_a_root(): void
    {
        $departed = $this->employee('ORG-001');
        $remaining = $this->employee('ORG-002', $departed);
        $departed->update(['employment_status' => 'inactive']);

        $chart = app(OrgChartService::class)->tree();

        $this->assertSame(1, $chart['total']);
        $this->assertCount(1, $chart['roots']);
        $this->assertSame($remaining->id, $chart['roots'][0]['id']);
        $this->assertSame(0, $chart['unplaced']);
    }

    public function test_employees_who_have_left_are_excluded(): void
    {
        $this->employee('ORG-001');
        $this->employee('ORG-002')->update(['employment_status' => 'inactive']);

        $chart = app(OrgChartService::class)->tree();

        $this->assertSame(1, $chart['total']);
        $this->assertCount(1, $chart['roots']);
    }

    public function test_someone_on_leave_stays_on_the_chart(): void
    {
        $head = $this->employee('ORG-001');
        $this->employee('ORG-002', $head)->update(['employment_status' => 'on_leave']);

        $chart = app(OrgChartService::class)->tree();

        $this->assertSame(2, $chart['total']);
        $this->assertSame('on_leave', $chart['roots'][0]['reports'][0]['employment_status']);
    }

    /**
     * supervisor_id is a self-referencing FK with nothing at the database level
     * preventing A -> B -> A. A naive recursive builder never terminates on
     * that; this proves the walk finishes and reports the affected people
     * rather than hanging or silently losing them.
     */
    public function test_a_supervisor_cycle_terminates_and_is_counted_as_unplaced(): void
    {
        $first = $this->employee('ORG-001');
        $second = $this->employee('ORG-002', $first);
        $first->update(['supervisor_id' => $second->id]);

        $chart = app(OrgChartService::class)->tree();

        $this->assertSame([], $chart['roots']);
        $this->assertSame(2, $chart['total']);
        $this->assertSame(0, $chart['placed']);
        $this->assertSame(2, $chart['unplaced']);
    }

    /**
     * A cycle hanging off a genuine root: the root's branch must still render
     * fully, and the walk must not revisit the looping edge.
     */
    public function test_a_cycle_below_a_root_does_not_loop_forever(): void
    {
        $root = $this->employee('ORG-001');
        $first = $this->employee('ORG-002', $root);
        $second = $this->employee('ORG-003', $first);
        $first->update(['supervisor_id' => $second->id]);

        $chart = app(OrgChartService::class)->tree();

        $this->assertCount(1, $chart['roots']);
        $this->assertSame($root->id, $chart['roots'][0]['id']);
        // The root itself is placed; the two-node loop below it cannot be
        // entered from the root because neither points back at the root.
        $this->assertSame(1, $chart['placed']);
        $this->assertSame(2, $chart['unplaced']);
    }

    private function employee(string $number, ?Employee $supervisor = null): Employee
    {
        return Employee::query()->create([
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'supervisor_id' => $supervisor?->id,
            'employee_number' => $number,
            'first_name' => 'Test',
            'last_name' => $number,
            'employment_status' => 'active',
            'hire_date' => '2026-01-01',
        ]);
    }
}
