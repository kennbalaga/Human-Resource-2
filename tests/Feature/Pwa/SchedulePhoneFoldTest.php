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
 * The schedule, read one day at a time.
 *
 * The grid answers "who is working this month"; this answers "what am I doing,
 * and who is on with me". The second half of that question is the one worth
 * testing hardest: it reads other people's rosters, so it has to stay inside
 * the reach the swap page already had — same department, same shift, clinical
 * staff only, never the reader.
 */
class SchedulePhoneFoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_both_folds_are_in_the_document(): void
    {
        $this->actingAs($this->anyEmployeeUser())
            ->get(route('schedules.index'))
            ->assertOk()
            ->assertSee('schedule-phone-fold', false)
            ->assertSee('schedule-desk-fold', false);
    }

    public function test_the_week_strip_offers_seven_days(): void
    {
        $html = $this->actingAs($this->anyEmployeeUser())
            ->get(route('schedules.index'))
            ->assertOk()
            ->getContent();

        // The <ol> is .schedule-phone-days, so counting the day class would
        // match it too. The letter belongs to a day and nothing else.
        $this->assertSame(7, substr_count($html, 'schedule-phone-letter'));
    }

    /**
     * The strip's days are links that refocus the card below, so a date in the
     * query string has to move it.
     */
    public function test_a_date_in_the_query_moves_the_day_card(): void
    {
        $focus = now(config('schedule.timezone'))->addDay();

        $this->actingAs($this->anyEmployeeUser())
            ->get(route('schedules.index', ['date' => $focus->toDateString()]))
            ->assertOk()
            ->assertSee($focus->format('l, j F'));
    }

    public function test_a_clinical_employee_sees_who_is_on_the_same_shift(): void
    {
        [$user, $colleague, $shift, $date] = $this->rosterTwoClinicalStaffOnOneShift();

        $this->actingAs($user)
            ->get(route('schedules.index', ['date' => $date]))
            ->assertOk()
            ->assertSee('On with you')
            ->assertSee($colleague->first_name, false);
    }

    /**
     * The reader is not on with themselves.
     */
    public function test_the_reader_is_not_listed_among_their_own_colleagues(): void
    {
        [$user, , , $date] = $this->rosterTwoClinicalStaffOnOneShift();

        $html = $this->actingAs($user)
            ->get(route('schedules.index', ['date' => $date]))
            ->assertOk()
            ->getContent();

        // One colleague was rostered, so exactly one chip belongs in the list.
        $this->assertSame(1, substr_count($html, 'schedule-phone-mate'));
    }

    /**
     * Colleague rosters are shown to clinical staff, because that is where the
     * swap page already draws the line. A non-clinical employee gets their own
     * day and nobody else's.
     */
    public function test_a_non_clinical_employee_is_shown_no_colleagues(): void
    {
        $department = Department::query()->where('category', '!=', Department::CATEGORY_CLINICAL)->firstOrFail();
        $employee = Employee::query()->where('department_id', $department->id)->whereNotNull('user_id')->first();

        if ($employee === null) {
            $this->markTestSkipped('The seed has no non-clinical employee with a login.');
        }

        $shift = Shift::query()->where('is_active', true)->firstOrFail();
        $date = now(config('schedule.timezone'))->addDay()->toDateString();

        RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'work_date' => $date,
            'status' => 'scheduled',
            'created_by' => $this->rosterAuthor()->id,
        ]));

        $this->actingAs($employee->user)
            ->get(route('schedules.index', ['date' => $date]))
            ->assertOk()
            ->assertDontSee('On with you');
    }

    /**
     * @return array{0: User, 1: Employee, 2: Shift, 3: string}
     */
    private function rosterTwoClinicalStaffOnOneShift(): array
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

        RosterWriteContext::allowUnattended(function () use ($staff, $shift, $date): void {
            foreach ($staff as $member) {
                ScheduleAssignment::query()->updateOrCreate(
                    ['employee_id' => $member->id, 'work_date' => $date],
                    ['shift_id' => $shift->id, 'status' => 'scheduled', 'created_by' => $this->rosterAuthor()->id],
                );
            }
        });

        return [$staff[0]->user, $staff[1], $shift, $date];
    }

    /** Somebody has to be accountable for a roster row; in a test it is HR. */
    private function rosterAuthor(): User
    {
        return User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }

    private function anyEmployeeUser(): User
    {
        return User::query()->with('employee')->where('email', 'employee@hrms.local')->firstOrFail();
    }
}
