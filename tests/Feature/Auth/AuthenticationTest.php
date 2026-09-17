<?php

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_employee_can_authenticate_with_employee_id(): void
    {
        $response = $this->post('/login', [
            'employee_id' => 'hr-mgr-2026-0001',
            'password' => 'ChangeMe123!',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
        $this->assertNotNull(auth()->user()->last_login_at);
    }

    public function test_employee_can_authenticate_with_work_email(): void
    {
        $response = $this->post('/login', [
            'employee_id' => 'hr.manager@hrms.local',
            'password' => 'ChangeMe123!',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
    }

    public function test_employee_cannot_authenticate_with_invalid_password(): void
    {
        $response = $this->from('/login')->post('/login', [
            'employee_id' => 'HR-MGR-2026-0001',
            'password' => 'incorrect-password',
        ]);

        $this->assertGuest();
        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('employee_id');
    }

    public function test_inactive_employee_cannot_authenticate(): void
    {
        Employee::query()
            ->where('employee_number', 'HR-MGR-2026-0001')
            ->update(['employment_status' => 'inactive']);

        $this->post('/login', [
            'employee_id' => 'HR-MGR-2026-0001',
            'password' => 'ChangeMe123!',
        ]);

        $this->assertGuest();
    }

    public function test_authenticated_employee_can_log_out(): void
    {
        $this->post('/login', [
            'employee_id' => 'HR-MGR-2026-0001',
            'password' => 'ChangeMe123!',
        ]);

        $response = $this->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/login');
    }

    public function test_login_form_has_no_remember_me_and_never_prefills_an_identifier(): void
    {
        $this->withCookie('hrms_remembered_employee', 'HR-MGR-2026-0001')
            ->get('/login')
            ->assertOk()
            ->assertDontSee('name="remember"', false)
            ->assertDontSee('value="HR-MGR-2026-0001"', false);
    }

    public function test_signing_in_clears_a_saved_identifier_and_issues_no_persistent_login(): void
    {
        $response = $this->withCookie('hrms_remembered_employee', 'HR-MGR-2026-0001')
            ->post('/login', [
                'employee_id' => 'HR-MGR-2026-0001',
                'password' => 'ChangeMe123!',
                'remember' => '1',
            ]);

        $response
            ->assertRedirect('/dashboard')
            ->assertCookieExpired('hrms_remembered_employee')
            ->assertCookieExpired(Auth::guard('web')->getRecallerName());
    }

    public function test_logout_does_not_leave_an_identifier_behind(): void
    {
        $this->post('/login', [
            'employee_id' => 'HR-MGR-2026-0001',
            'password' => 'ChangeMe123!',
        ]);

        $cookie = $this->post('/logout')
            ->assertRedirect('/login')
            ->getCookie('hrms_remembered_employee', false);

        // Only ever the removal queued at sign-in, never a saved identifier.
        $this->assertTrue($cookie === null || $cookie->isCleared());
    }

    public function test_legacy_persistent_auth_cookie_is_rejected(): void
    {
        $user = $this->userForEmployee('HR-MGR-2026-0001');
        $token = Str::random(60);
        $user->forceFill(['remember_token' => $token])->save();
        $guard = Auth::guard('web');
        $recallerName = $guard->getRecallerName();
        $recallerValue = implode('|', [
            $user->getKey(),
            $token,
            $guard->hashPasswordForCookie($user->password),
        ]);

        $this->flushSession();
        Auth::forgetGuards();

        $this->withCookie($recallerName, $recallerValue)
            ->get('/dashboard')
            ->assertRedirect('/login')
            ->assertCookieExpired($recallerName)
            ->assertCookieMissing('hrms_remembered_employee');

        $this->assertGuest();
    }

    public function test_keep_alive_and_json_logout_support_the_inactivity_timer(): void
    {
        $user = $this->userForEmployee('HR-MGR-2026-0001');

        $this->actingAs($user)
            ->getJson(route('session.keep-alive'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertJson([
                'active' => true,
                'expires_in' => (int) config('session.lifetime') * 60,
            ]);

        $this->postJson(route('logout'))
            ->assertOk()
            ->assertJsonPath('redirect', route('login'));

        $this->assertGuest();
    }

    public function test_keep_alive_answers_an_account_that_still_owes_two_factor_enrollment(): void
    {
        // The timer runs on the enrollment page too. A 403 here read as a
        // session that had ended, and signed the person out before they could
        // finish setting up the authenticator the page was asking for.
        config(['security.two_factor.required_roles' => ['hr-manager']]);
        $user = $this->userForEmployee('HR-MGR-2026-0001');

        $this->actingAs($user)
            ->getJson(route('session.keep-alive'))
            ->assertOk()
            ->assertJsonPath('active', true);

        $this->get('/dashboard')->assertRedirect(route('settings.edit').'#two-factor');
    }

    private function userForEmployee(string $employeeNumber): User
    {
        return User::query()
            ->whereHas('employee', fn ($query) => $query->where('employee_number', $employeeNumber))
            ->firstOrFail();
    }
}
