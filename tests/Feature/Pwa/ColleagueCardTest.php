<?php

namespace Tests\Feature\Pwa;

use App\Models\Department;
use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The colleague card.
 *
 * Reached only by tapping a name on the schedule's day card. Since a URL is a
 * guess away, most of what matters here is the refusals: the card must not
 * become the staff directory the phone deliberately does not have.
 */
class ColleagueCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_a_colleague_on_the_same_shift_opens(): void
    {
        [$me, $colleague, $date] = $this->rosterTogether();

        $this->actingAs($me->user)
            ->get(route('colleagues.show', ['employee' => $colleague->id, 'date' => $date]))
            ->assertOk()
            ->assertSee($colleague->full_name)
            ->assertSee('On shift with you');
    }

    /**
     * The card exists so somebody can ask the person beside them to take a
     * shift. That action is the point of it.
     */
    public function test_it_offers_the_swap(): void
    {
        [$me, $colleague, $date] = $this->rosterTogether();

        $this->actingAs($me->user)
            ->get(route('colleagues.show', ['employee' => $colleague->id, 'date' => $date]))
            ->assertOk()
            ->assertSee('to swap a shift');
    }

    /**
     * There is nothing to show, so it says nothing rather than 403 — a refusal
     * would confirm something about a roster the reader may not ask about.
     */
    public function test_someone_you_do_not_share_the_shift_with_is_not_found(): void
    {
        [$me, , $date] = $this->rosterTogether();

        $stranger = Employee::query()
            ->where('department_id', '!=', $me->department_id)
            ->whereNotNull('user_id')
            ->firstOrFail();

        $this->actingAs($me->user)
            ->get(route('colleagues.show', ['employee' => $stranger->id, 'date' => $date]))
            ->assertForbidden();
    }

    public function test_a_colleague_rostered_on_another_day_is_not_found(): void
    {
        [$me, $colleague] = $this->rosterTogether();

        $this->actingAs($me->user)
            ->get(route('colleagues.show', [
                'employee' => $colleague->id,
                'date' => now(config('schedule.timezone'))->addDays(30)->toDateString(),
            ]))
            ->assertNotFound();
    }

    /**
     * No contact detail, ever. That is the rule the desktop record follows and
     * the one thing this card could get badly wrong.
     */
    public function test_it_shows_no_contact_details(): void
    {
        [$me, $colleague, $date] = $this->rosterTogether();

        $response = $this->actingAs($me->user)
            ->get(route('colleagues.show', ['employee' => $colleague->id, 'date' => $date]))
            ->assertOk();

        if ($colleague->contact_number) {
            $response->assertDontSee($colleague->contact_number);
        }

        if ($colleague->user?->email) {
            $response->assertDontSee($colleague->user->email);
        }

        $response->assertSee('No contact details here');
    }

    public function test_you_cannot_open_your_own_card(): void
    {
        [$me, , $date] = $this->rosterTogether();

        $this->actingAs($me->user)
            ->get(route('colleagues.show', ['employee' => $me->id, 'date' => $date]))
            ->assertNotFound();
    }

    /**
     * @return array{0: Employee, 1: Employee, 2: string}
     */
    private function rosterTogether(): array
    {
        $department = Department::query()->where('category', Department::CATEGORY_CLINICAL)->firstOrFail();

        $staff = Employee::query()
            ->where('department_id', $department->id)
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->take(2)
            ->get();

        if ($staff->count() < 2) {
            $this->markTestSkipped('The seed has fewer than two clinical employees with logins.');
        }

        $shift = Shift::query()->where('is_active', true)->firstOrFail();
        $date = now(config('schedule.timezone'))->addDay()->toDateString();
        $author = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        RosterWriteContext::allowUnattended(function () use ($staff, $shift, $date, $author): void {
            foreach ($staff as $member) {
                ScheduleAssignment::query()->updateOrCreate(
                    ['employee_id' => $member->id, 'work_date' => $date],
                    ['shift_id' => $shift->id, 'status' => 'scheduled', 'created_by' => $author->id],
                );
            }
        });

        return [$staff[0], $staff[1], $date];
    }
}
