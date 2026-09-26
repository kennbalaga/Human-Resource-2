<?php

namespace Tests\Feature\Pwa;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The phone's tab bar.
 *
 * Which shell a page gets is decided in Blade, by role, and then shown or
 * hidden in CSS by viewport and pointer. These tests cover the Blade half —
 * that the bar is rendered for the accounts it serves and withheld from the
 * ones it would strand.
 *
 * The stranding is the failure worth guarding. The five tabs name an
 * employee's destinations only; they have no Organization, no Reports, no room
 * board. A restricted role reaching a narrow layout (which happens when
 * MOBILE_RESTRICTED_ROLES is emptied for testing) must therefore keep the
 * drawer, or it is navigation with no way to most of the app.
 */
class MobileTabBarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_an_employee_gets_the_tab_bar(): void
    {
        $response = $this->actingAs($this->userWithEmail('employee@hrms.local'))
            ->get('/dashboard')
            ->assertOk();

        $response->assertSee('app-tabbar', false);
        // The body class every rule that retires the drawer is keyed to.
        $response->assertSee('has-tabbar', false);

        foreach ([
            route('dashboard'),
            route('attendance.index'),
            route('schedules.index'),
            route('requests.index'),
            route('more'),
        ] as $destination) {
            $response->assertSee($destination, false);
        }
    }

    #[DataProvider('deskBoundAccounts')]
    public function test_desk_bound_roles_keep_the_drawer(string $email): void
    {
        $response = $this->actingAs($this->userWithEmail($email))
            ->get('/dashboard')
            ->assertOk();

        $response->assertDontSee('app-tabbar', false);
        $response->assertDontSee('has-tabbar', false);
        // The rail is still there, which is the whole point of withholding it.
        $response->assertSee('appSidebar', false);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function deskBoundAccounts(): array
    {
        return [
            'system administrator' => ['admin@hrms.local'],
            'hr manager' => ['hr.manager@hrms.local'],
        ];
    }

    /**
     * The rail stays in the markup for an employee too — it is hidden by CSS,
     * not removed — so a viewport that grows past the breakpoint has something
     * to show without a page load.
     */
    public function test_the_rail_is_hidden_rather_than_dropped(): void
    {
        $this->actingAs($this->userWithEmail('employee@hrms.local'))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('appSidebar', false);
    }

    public function test_the_current_tab_is_marked_on_the_page_it_opens(): void
    {
        $user = $this->userWithEmail('employee@hrms.local');

        $this->actingAs($user)->get('/attendance')
            ->assertOk()
            ->assertSee('aria-current="page"', false);

        // Timesheets belongs to the Attendance tab rather than a sixth one, so
        // it must light the same tab rather than falling through to More.
        $this->actingAs($user)->get('/timesheets')
            ->assertOk()
            ->assertSee('aria-current="page"', false);
    }

    private function userWithEmail(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }
}
