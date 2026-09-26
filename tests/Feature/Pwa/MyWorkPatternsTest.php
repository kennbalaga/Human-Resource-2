<?php

namespace Tests\Feature\Pwa;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * My work patterns.
 *
 * Four readings moved off the bottom of the staff dashboard so the phone's one
 * fold could be about the shift in front of the reader. The move is only sound
 * if nothing was lost on the way, which is what most of this covers.
 *
 * The other half is that moving an employee's own figures onto a page of their
 * own must not have opened the analytics module to them. It did not: this
 * reads one employee's record, theirs, through their own account.
 */
class MyWorkPatternsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('insights.mine'))->assertRedirect('/login');
    }

    public function test_an_employee_sees_their_own_patterns(): void
    {
        $this->actingAs($this->employeeUser())
            ->get(route('insights.mine'))
            ->assertOk()
            ->assertSee('My work patterns')
            // The four cards that used to sit at the bottom of the dashboard.
            ->assertSee('my-analytics', false)
            ->assertSee('attendance-summary', false)
            ->assertSee('overtime-summary', false);
    }

    /**
     * Reachable from More, which is the only place on a phone that names it.
     */
    public function test_more_links_to_it(): void
    {
        $this->actingAs($this->employeeUser())
            ->get(route('more'))
            ->assertOk()
            ->assertSee(route('insights.mine'), false);
    }

    /**
     * The supervisory analytics module is a different thing and stays where it
     * was. An employee reaching their own patterns must not have been handed
     * the hospital's.
     */
    public function test_it_does_not_open_the_workforce_analytics_module(): void
    {
        $this->actingAs($this->employeeUser())
            ->get(route('analytics.index'))
            ->assertForbidden();
    }

    public function test_an_account_with_no_employee_record_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('insights.mine'))
            ->assertForbidden();
    }

    private function employeeUser(): User
    {
        return User::query()->with('employee')->where('email', 'employee@hrms.local')->firstOrFail();
    }
}
