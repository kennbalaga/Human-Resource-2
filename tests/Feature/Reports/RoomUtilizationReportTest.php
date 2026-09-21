<?php

namespace Tests\Feature\Reports;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Room;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Reports\RoomUtilizationReport;
use App\Services\Scheduling\RosterWriteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which rooms were staffed over a period, and how much of it was borrowed.
 */
class RoomUtilizationReportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Room $theatre;

    private Shift $morning;

    private Department $surgery;

    private Department $medicine;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->surgery = Department::query()->where('code', 'SURG')->firstOrFail();
        $this->medicine = Department::query()->where('code', 'IM')->firstOrFail();
        $this->theatre = Room::query()->where('code', 'OR-1')->firstOrFail();
        $this->morning = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();
        // Clear of the demo roster the seeders lay down around today, so the
        // counts below are this test's own.
        $this->date = '2027-06-15';
    }

    public function test_the_report_is_listed_and_opens(): void
    {
        $this->actingAs($this->manager)
            ->get(route('reports.show', 'room-utilization'))
            ->assertOk()
            ->assertSee('Room Utilization Reports')
            ->assertSee('Rooms used');
    }

    public function test_it_counts_placements_and_borrowed_shifts(): void
    {
        $own = $this->employee('RU-0001', $this->surgery);
        $borrowed = $this->employee('RU-0002', $this->medicine);

        $this->place($own, false);
        $this->place($borrowed, true);

        $summary = collect(app(RoomUtilizationReport::class)->summary($this->filters(), $this->manager))
            ->keyBy('label');

        $this->assertSame('2', $summary['Placements']['value']);
        $this->assertSame('1', $summary['Rooms used']['value']);
        $this->assertSame('1', $summary['Borrowed shifts']['value']);
    }

    /**
     * A theatre's use is a fact about the theatre. Filtering by the staff's home
     * unit would drop every borrowed nurse from the report that exists to count
     * them, so the unit filter reads the room's unit instead.
     */
    public function test_the_unit_filter_follows_the_room_not_the_employee(): void
    {
        $borrowed = $this->employee('RU-0003', $this->medicine);
        $this->place($borrowed, true);

        $report = app(RoomUtilizationReport::class);

        $rows = $report->scopedQuery($this->filters(['department_id' => $this->surgery->id]), $this->manager)->get();

        $this->assertCount(1, $rows, 'A nurse lent to Surgery belongs in the Surgery room report.');
        $this->assertSame($this->theatre->id, $rows->first()->room_id);
    }

    public function test_shifts_with_no_room_are_reported_rather_than_ignored(): void
    {
        $nurse = $this->employee('RU-0004', $this->surgery);

        RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $nurse->id,
            'shift_id' => $this->morning->id,
            'work_date' => $this->date,
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
            'created_via' => 'manual',
        ]));

        $summary = collect(app(RoomUtilizationReport::class)->summary($this->filters(), $this->manager))
            ->keyBy('label');

        $this->assertSame('1', $summary['No room recorded']['value']);
        $this->assertSame('0', $summary['Placements']['value']);
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function filters(array $extra = []): array
    {
        return $extra + ['date_from' => $this->date, 'date_to' => $this->date];
    }

    private function employee(string $number, Department $department): Employee
    {
        $position = Position::query()->create([
            'department_id' => $department->id,
            'code' => 'POS-'.$number,
            'title' => 'Staff Nurse',
            'seniority_rank' => 3,
            'is_active' => true,
        ]);

        return Employee::query()->create([
            'department_id' => $department->id,
            'position_id' => $position->id,
            'employee_number' => $number,
            'first_name' => 'Room',
            'last_name' => 'Nurse '.$number,
            'employment_status' => 'active',
            'hire_date' => '2024-01-01',
        ]);
    }

    private function place(Employee $employee, bool $crossUnit): ScheduleAssignment
    {
        return RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $this->morning->id,
            'room_id' => $this->theatre->id,
            'cross_unit' => $crossUnit,
            'work_date' => $this->date,
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
            'created_via' => 'manual',
        ]));
    }
}
