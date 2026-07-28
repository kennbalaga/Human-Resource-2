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
            ->assertSee('HR-0002')
            ->assertSee(route('settings.edit'), false);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee(route('profile.show'), false)
            ->assertSee(route('settings.edit'), false);
    }

    public function test_standard_employees_do_not_see_system_administration_tools_in_settings(): void
    {
        $this->actingAs($this->employeeUser())->get(route('settings.edit'))
            ->assertOk()
            ->assertDontSee('Operational tools')
            ->assertDontSee(route('integrations.index'), false)
            ->assertDontSee(route('audit-logs.index'), false);
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
            'employee_number' => 'HR-0002',
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
            'email_notifications' => '1',
            'attendance_reminders' => '0',
            'schedule_updates' => '1',
            'leave_updates' => '1',
            'compact_navigation' => '1',
            'reduce_motion' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'timezone' => 'Asia/Manila',
            'theme' => 'dark',
            'attendance_reminders' => false,
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

    public function test_topbar_theme_toggle_endpoint_persists_a_valid_theme(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user)->patchJson('/settings/theme', ['theme' => 'dark'])
            ->assertOk()
            ->assertJson(['theme' => 'dark']);

        $this->assertDatabaseHas('user_preferences', ['user_id' => $user->id, 'theme' => 'dark']);
        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('data-theme="dark"', false)
            ->assertSee('data-theme-toggle', false);
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

    private function employeeUser(): User
    {
        return User::query()->with('employee')->where('email', 'employee@hrms.local')->firstOrFail();
    }
}
