<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_guests_are_redirected_from_profile_and_settings(): void
    {
        $this->get('/profile')->assertRedirect('/login');
        $this->get('/settings')->assertRedirect('/login');
    }

    public function test_authenticated_employee_can_view_profile_and_navigation_links(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertSee('My Profile')
            ->assertSee('HR-OFFICER-2026-0001')
            ->assertSee(route('settings.edit'), false);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee(route('profile.show'), false)
            ->assertSee(route('settings.edit'), false);
    }

    public function test_profile_shows_the_attributes_the_statutory_leave_gates_read(): void
    {
        $user = $this->employeeUser();
        $user->employee->update([
            'gender' => 'female',
            'solo_parent_id_number' => 'SP-2026-0042',
            'solo_parent_id_expires_on' => now()->addYear()->toDateString(),
        ]);

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertSee('Gender')
            ->assertSee('Female')
            ->assertSee('SP-2026-0042')
            ->assertSee('valid to');
    }

    /**
     * A lapsed ID quietly withdraws solo parent leave. The first the employee
     * would otherwise know of it is a refused request, so the profile says so
     * and points at the renewal.
     */
    public function test_profile_explains_a_lapsed_solo_parent_id(): void
    {
        $user = $this->employeeUser();
        $user->employee->update([
            'gender' => 'male',
            'solo_parent_id_number' => 'SP-2020-0007',
            'solo_parent_id_expires_on' => now()->subMonth()->toDateString(),
        ]);

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertSee('SP-2020-0007')
            ->assertSee('expired')
            ->assertSee('Renew it with the DSWD');
    }

    public function test_profile_reads_an_unset_attribute_as_not_recorded(): void
    {
        $user = $this->employeeUser();
        $user->employee->update([
            'gender' => null,
            'solo_parent_id_number' => null,
            'solo_parent_id_expires_on' => null,
        ]);

        $this->actingAs($user)->get('/profile')->assertOk()->assertSeeInOrder(['Gender', 'Not recorded']);
    }

    public function test_standard_employees_do_not_see_system_administration_tools_in_settings(): void
    {
        $this->actingAs($this->employeeUser())->get(route('settings.edit'))
            ->assertOk()
            ->assertDontSee('Operational tools')
            ->assertDontSee(route('integrations.index'), false)
            ->assertDontSee(route('audit-logs.index'), false);
    }

    public function test_hr_managers_do_not_see_the_operational_tools_in_settings(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)->get(route('settings.edit'))
            ->assertOk()
            ->assertDontSee('Operational tools')
            ->assertDontSee(route('integrations.index'), false)
            ->assertDontSee(route('audit-logs.index'), false);
    }

    public function test_the_system_administrator_reaches_both_tools_from_settings(): void
    {
        $administrator = User::query()->where('email', 'admin@hrms.local')->firstOrFail();

        $this->actingAs($administrator)->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Operational tools')
            ->assertSee(route('integrations.index'), false)
            ->assertSee(route('audit-logs.index'), false);
    }

    public function test_employee_can_update_only_personal_contact_information(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user)->patch('/profile', [
            'contact_number' => '+63 917 123 4567',
            'address' => '123 Workforce Avenue, Quezon City',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('employees', [
            'id' => $user->employee->id,
            'employee_number' => 'HR-OFFICER-2026-0001',
            'contact_number' => '+63 917 123 4567',
            'address' => '123 Workforce Avenue, Quezon City',
        ]);
    }

    public function test_user_can_save_preferences_and_the_layout_applies_them(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user)->patch('/settings/preferences', [
            'timezone' => 'UTC',
            'theme' => 'dark',
            'compact_navigation' => '1',
            'reduce_motion' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'timezone' => 'Asia/Manila',
            'theme' => 'dark',
            'compact_navigation' => true,
            'reduce_motion' => true,
        ]);

        $this->actingAs($user)->get('/settings')
            ->assertOk()
            ->assertSee('data-theme="dark"', false)
            ->assertSee('compact-navigation', false)
            ->assertSee('reduce-motion', false)
            ->assertDontSee('Display timezone');
    }

    public function test_topbar_theme_control_persists_a_valid_theme(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user)->patchJson('/settings/theme', ['theme' => 'dark'])
            ->assertOk()
            ->assertJson(['theme' => 'dark']);

        $this->assertDatabaseHas('user_preferences', ['user_id' => $user->id, 'theme' => 'dark']);

        $content = $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('data-theme="dark"', false)
            ->assertSee('data-theme-set="light"', false)
            ->assertSee('data-theme-set="dark"', false)
            ->assertSee('data-theme-set="system"', false)
            ->getContent();

        /*
         * All three states are offered from the account menu, and the stored one
         * is the single option marked selected. The count matters: the control
         * this replaced could only express two of the three, and marking the
         * wrong one is exactly how `system` used to read as unselected while it
         * was the setting in force.
         */
        $this->assertSame(1, substr_count($content, 'aria-pressed="true"'));
        $this->assertMatchesRegularExpression('/data-theme-set="dark"[^>]*aria-pressed="true"/', $content);
    }

    public function test_invalid_theme_is_rejected(): void
    {
        $this->actingAs($this->employeeUser())->patch('/settings/theme', ['theme' => 'neon'])
            ->assertRedirect()
            ->assertSessionHasErrors('theme');
    }

    public function test_account_email_must_be_unique(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user)->patch('/settings/account', [
            'email' => 'hr.manager@hrms.local',
        ])->assertSessionHasErrors('email');

        $this->assertSame('employee@hrms.local', $user->fresh()->email);
    }

    public function test_password_change_requires_current_password_and_revokes_api_tokens(): void
    {
        $user = $this->employeeUser();
        $user->createToken('existing-device');

        $this->actingAs($user)->put('/settings/password', [
            'current_password' => 'ChangeMe123!',
            'password' => 'NewSecurePass456!',
            'password_confirmation' => 'NewSecurePass456!',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertTrue(Hash::check('NewSecurePass456!', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user)->put('/settings/password', [
            'current_password' => 'incorrect-password',
            'password' => 'NewSecurePass456',
            'password_confirmation' => 'NewSecurePass456',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('ChangeMe123!', $user->fresh()->password));
    }

    /**
     * The side nav is the only way to reach a panel further down the page, so
     * every rendered panel must have a link pointing at it.
     */
    public function test_every_settings_panel_is_reachable_from_the_section_nav(): void
    {
        foreach (['admin@hrms.local', 'hr.manager@hrms.local', 'employee@hrms.local'] as $email) {
            $this->flushSession();
            $user = User::query()->where('email', $email)->firstOrFail();
            $html = $this->actingAs($user)->get('/settings')->assertOk()->getContent();

            // The attendance panel spreads its attributes over several lines, so
            // the tag is matched loosely rather than as one flat string.
            preg_match_all('/<article[^>]*class="panel settings-panel"[^>]*id="([a-z-]+)"/', $html, $panels);
            preg_match_all('/<a href="#([a-z-]+)"/', $html, $links);

            $this->assertNotEmpty($panels[1], "No settings panels rendered for {$email}.");
            $this->assertEmpty(
                array_diff($panels[1], $links[1]),
                "Settings panels without a nav link for {$email}: ".implode(', ', array_diff($panels[1], $links[1])),
            );
            $this->assertEmpty(
                array_diff($links[1], $panels[1]),
                "Settings nav links pointing nowhere for {$email}: ".implode(', ', array_diff($links[1], $panels[1])),
            );
        }
    }

    public function test_settings_separates_personal_from_system_administration(): void
    {
        $admin = User::query()->where('email', 'admin@hrms.local')->firstOrFail();
        $response = $this->actingAs($admin)->get('/settings')->assertOk();

        // Both nav groups are present, and the panels themselves run personal
        // first, then system administration.
        $response->assertSee('Your account')->assertSee('System administration');
        $response->assertSeeInOrder([
            'Email address', 'Color theme', 'Change password',
            'Employee ID generation', 'Two-factor enforcement', 'Attendance capture mode',
        ], false);

        // A plain employee has no system panels, so that heading must not appear.
        $this->flushSession();
        $this->actingAs($this->employeeUser())->get('/settings')->assertOk()->assertDontSee('System administration');
    }

    private function employeeUser(): User
    {
        return User::query()->with('employee')->where('email', 'employee@hrms.local')->firstOrFail();
    }
}
