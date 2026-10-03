<?php

namespace Tests\Feature\Schedule;

use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The month cell shows three shifts and then a count. On a busy day that leaves
 * most of the roster behind it -- ten assigned means seven invisible -- so the
 * count has to lead somewhere. It used to be a bare <span> with nothing
 * listening to it, which reads as a promise the page does not keep.
 */
class ScheduleMonthOverflowTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-10-05';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_a_day_with_more_shifts_than_the_cell_shows_offers_a_way_to_see_the_rest(): void
    {
        $this->rosterOf(6);

        $total = ScheduleAssignment::query()->whereDate('work_date', self::DAY)->count();

        $response = $this->actingAs($this->manager())
            ->get(route('schedules.index', ['view' => 'month', 'date' => self::DAY]))
            ->assertOk();

        // Asserted through the label for this one date rather than a bare
        // "+N more": the month shows a whole grid, and other days carry their
        // own counts, so a loose match would pass on somebody else's cell.
        $response->assertSee('Show all '.$total.' shifts on October 5', false);
        $response->assertSee('view=week', false);
        $response->assertSee('date='.self::DAY, false);
    }

    public function test_the_link_lands_on_a_view_that_shows_every_shift_that_day(): void
    {
        $employees = $this->rosterOf(6);

        $response = $this->actingAs($this->manager())
            ->get(route('schedules.index', ['view' => 'week', 'date' => self::DAY]))
            ->assertOk();

        // Every one of the six, including the three the month view had to drop.
        foreach ($employees as $employee) {
            $response->assertSee($employee->last_name, false);
        }
    }

    public function test_a_quiet_day_does_not_advertise_a_link_to_nothing(): void
    {
        // A date the seed leaves alone, so the cell really is quiet.
        $quiet = '2027-03-07';

        $this->actingAs($this->manager())
            ->get(route('schedules.index', ['view' => 'month', 'date' => $quiet]))
            ->assertOk()
            ->assertDontSee('Show all', false);
    }

    public function test_the_link_carries_the_filters_the_page_is_already_narrowed_by(): void
    {
        $employees = $this->rosterOf(6);
        $departmentId = $employees->first()->department_id;

        // Otherwise following it widens the view back out to the whole
        // hospital, which is not what somebody looking at one ward asked for.
        $this->actingAs($this->manager())
            ->get(route('schedules.index', [
                'view' => 'month',
                'date' => self::DAY,
                'department_id' => $departmentId,
            ]))
            ->assertOk()
            ->assertSee('department_id='.$departmentId, false);
    }

    /** @return Collection<int, Employee> */
    private function rosterOf(int $count)
    {
        $shift = Shift::query()->firstOrFail();
        $employees = Employee::query()
            ->where('employment_status', 'active')
            ->notArchived()
            ->limit($count)
            ->get();

        $this->assertCount($count, $employees, 'The seed does not hold enough active employees for this test.');

        // Roster writes are gated: the model refuses a create outside this
        // context, so that every assignment has a recorded actor behind it.
        foreach ($employees as $employee) {
            RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'work_date' => Carbon::parse(self::DAY)->toDateString(),
                'status' => 'scheduled',
                'created_by' => $this->manager()->id,
            ]));
        }

        return $employees;
    }

    private function manager(): User
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('slug', ['hr-manager', 'system-administrator']))
            ->firstOrFail();
    }
}
