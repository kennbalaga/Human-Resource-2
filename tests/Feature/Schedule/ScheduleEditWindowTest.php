<?php

namespace Tests\Feature\Schedule;

use App\Models\Employee;
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
 * A roster stops being a plan once its day begins. Past days are a closed
 * record, today may only move through an approved shift swap, and the days
 * ahead stay fully editable.
 */
class ScheduleEditWindowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }

    public function test_an_assignment_on_a_started_day_cannot_be_removed(): void
    {
        $today = now(config('schedule.timezone'))->startOfDay();

        foreach ([$today, $today->copy()->subDay()] as $date) {
            $assignment = ScheduleAssignment::query()->whereDate('work_date', $date->toDateString())->first();
            if ($assignment === null) {
                continue;
            }

            $this->actingAs($this->manager)
                ->delete(route('schedules.destroy', $assignment))
                ->assertSessionHasErrors('schedule');

            $this->assertDatabaseHas('schedule_assignments', ['id' => $assignment->id]);
        }
    }

    public function test_the_calendar_marks_started_days_and_offers_quick_add_only_ahead(): void
    {
        $today = now(config('schedule.timezone'))->startOfDay();

        $response = $this->actingAs($this->manager)
            ->get(route('schedules.index', ['date' => $today->toDateString(), 'view' => 'month']))
            ->assertOk()
            ->assertSee('is-locked-day', false)
            ->assertSee('data-detail-lock', false);

        $this->assertStringNotContainsString(
            'data-quick-schedule-date="'.$today->toDateString().'"',
            $response->getContent(),
        );
        $this->assertStringContainsString(
            'data-quick-schedule-date="'.$today->copy()->addDay()->toDateString().'"',
            $response->getContent(),
        );
    }

    public function test_an_upcoming_assignment_can_still_be_removed(): void
    {
        $assignment = ScheduleAssignment::query()
            ->whereDate('work_date', '>', now(config('schedule.timezone'))->toDateString())
            ->firstOrFail();

        $this->actingAs($this->manager)
            ->delete(route('schedules.destroy', $assignment))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('schedule_assignments', ['id' => $assignment->id]);
    }

    public function test_an_approved_shift_swap_still_moves_todays_shift(): void
    {
        $today = now(config('schedule.timezone'))->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $shift = Shift::query()->where('is_active', true)->firstOrFail();

        // Two people otherwise free across both days, so the swap turns only on
        // the date rule under test and not on someone's other commitments.
        [$requester, $target] = Employee::query()
            ->where('employment_status', 'active')
            ->whereDoesntHave('scheduleAssignments', fn ($query) => $query
                ->whereBetween('work_date', [$today->copy()->subDays(3), $tomorrow->copy()->addDays(3)]))
            ->take(2)
            ->get()
            ->all();

        $todaysAssignment = RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $requester->id,
            'shift_id' => $shift->id,
            'work_date' => $today->toDateString(),
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
        ]));
        $tomorrowsAssignment = RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $target->id,
            'shift_id' => $shift->id,
            'work_date' => $tomorrow->toDateString(),
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
        ]));

        $swap = ShiftSwapRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'requester_employee_id' => $requester->id,
            'requester_assignment_id' => $todaysAssignment->id,
            'target_employee_id' => $target->id,
            'target_assignment_id' => $tomorrowsAssignment->id,
            'reason' => 'Family emergency this morning.',
            'status' => 'pending_manager',
            'target_responded_at' => now(),
        ]);

        app(ShiftSwapService::class)->approve($swap, $this->manager, null);

        $this->assertSame('approved', $swap->refresh()->status);
        $this->assertSame($target->id, $todaysAssignment->refresh()->employee_id);
        $this->assertSame($requester->id, $tomorrowsAssignment->refresh()->employee_id);
    }
}
