<?php

namespace Tests\Feature\Pwa;

use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The phone's fifth tab.
 *
 * It gathers destinations rather than adding any, so what these assert is that
 * it stayed a gathering: every row has to be somewhere the reader could already
 * go, and the page must not become a second place where reach is decided.
 */
class MoreScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('more'))->assertRedirect('/login');
    }

    public function test_it_gathers_the_destinations_the_other_tabs_do_not_carry(): void
    {
        $response = $this->actingAs($this->employeeUser())
            ->get(route('more'))
            ->assertOk();

        foreach ([
            route('profile.show'),
            route('profile.badge'),
            route('timesheets.index'),
            route('payslips.index'),
            route('insights.mine'),
            route('settings.edit'),
            route('privacy-policy'),
        ] as $destination) {
            $response->assertSee($destination, false);
        }

        $response->assertSee('Sign out');
    }

    /**
     * The list is exactly six rows.
     *
     * Asserted by count rather than by absence, because the topbar's bell menu
     * puts the notifications URL on every page in the app — so "does not link
     * to notifications" cannot be tested against the whole document, only
     * against this list's own shape.
     *
     * Seven is the design: badge, timesheets, payslips and work patterns under
     * Your records; app lock, settings and privacy under This device.
     * Notifications is not among them on purpose — the bell reaches it from
     * every screen, and a row here would be the same door twice on the one
     * screen whose entire job is being an unambiguous index.
     */
    public function test_the_list_is_exactly_the_seven_intended_rows(): void
    {
        $html = $this->actingAs($this->employeeUser())
            ->get(route('more'))
            ->assertOk()
            ->getContent();

        $this->assertSame(7, substr_count($html, 'class="more-row"'));
    }

    public function test_a_draft_timesheet_is_counted_for_this_employee_only(): void
    {
        $user = $this->employeeUser();

        Timesheet::query()->where('employee_id', $user->employee->id)->delete();

        $this->actingAs($user)->get(route('more'))->assertOk()->assertDontSee('to submit');

        Timesheet::query()->create([
            'employee_id' => $user->employee->id,
            'period_start' => '2026-09-16',
            'period_end' => '2026-09-30',
            'status' => 'draft',
        ]);

        $this->actingAs($user)->get(route('more'))->assertOk()->assertSee('1 to submit');
    }

    /**
     * Appearance saves itself from here.
     *
     * The phone has no account menu — the topbar's went when the tab bar
     * replaced the drawer — and that menu was the only control in the app that
     * persisted a theme on click. The settings radios preview until the form is
     * submitted, so without this a phone user tapped Dark, saw it, and watched
     * it revert on the next screen.
     */
    public function test_the_theme_can_be_set_and_saved_from_here(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user)
            ->get(route('more'))
            ->assertOk()
            // The contract theme.js reads: the value and where to persist it.
            ->assertSee('data-theme-set="dark"', false)
            ->assertSee(route('settings.theme.update'), false);

        $this->actingAs($user)
            ->patchJson(route('settings.theme.update'), ['theme' => 'dark'])
            ->assertOk();

        $this->assertSame('dark', $user->fresh()->preference->theme);
    }

    public function test_an_account_without_an_employee_record_still_opens(): void
    {
        // An administrator account need not be linked to an employee. The page
        // has to degrade rather than 500: the badge row is the only part that
        // depends on one.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('more'))
            ->assertOk()
            ->assertDontSee(route('profile.badge'), false);
    }

    private function employeeUser(): User
    {
        return User::query()->with('employee')->where('email', 'employee@hrms.local')->firstOrFail();
    }
}
