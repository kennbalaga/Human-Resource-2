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

    public function test_remember_me_saves_identity_without_creating_persistent_authentication(): void
    {
        $response = $this->post('/login', [
            'employee_id' => 'HR-MGR-2026-0001',
            'password' => 'ChangeMe123!',
            'remember' => '1',
        ]);

        $recallerName = Auth::guard('web')->getRecallerName();
        $response
            ->assertCookie('hrms_remembered_employee', 'HR-MGR-2026-0001')
            ->assertCookieExpired($recallerName);
        $rememberedEmployee = $response->getCookie('hrms_remembered_employee')?->getValue();
        $this->flushSession();
        Auth::forgetGuards();

        $this->withCookie('hrms_remembered_employee', (string) $rememberedEmployee)
            ->get('/dashboard')
            ->assertRedirect('/login');

        $this->assertGuest();

        $this->withCookie('hrms_remembered_employee', (string) $rememberedEmployee)
            ->get('/login')
            ->assertOk()
            ->assertSee('value="HR-MGR-2026-0001"', false)
            ->assertDontSee('value="ChangeMe123!"', false);
    }

    public function test_logout_keeps_only_the_opted_in_employee_id_for_the_unchanged_login_form(): void
    {
        $login = $this->post('/login', [
            'employee_id' => 'HR-MGR-2026-0001',
            'password' => 'ChangeMe123!',
            'remember' => '1',
        ]);

        $login->assertCookie('hrms_remembered_employee', 'HR-MGR-2026-0001');
        $rememberedEmployee = $login->getCookie('hrms_remembered_employee')?->getValue();

        $this->post('/logout')
            ->assertRedirect('/login')
            ->assertCookie('hrms_remembered_employee', 'HR-MGR-2026-0001');

        Auth::forgetGuards();

        $this->withCookie('hrms_remembered_employee', (string) $rememberedEmployee)
            ->get('/login')
            ->assertOk()
            ->assertSee('value="HR-MGR-2026-0001"', false)
            ->assertSee('id="remember" name="remember" value="1" checked', false)
            ->assertDontSee('value="ChangeMe123!"', false);
    }

    public function test_remember_me_offers_back_the_work_email_when_that_is_what_was_typed(): void
    {
        $login = $this->post('/login', [
            'employee_id' => 'HR.Manager@HRMS.local',
            'password' => 'ChangeMe123!',
            'remember' => '1',
        ]);

        $login->assertCookie('hrms_remembered_employee', 'hr.manager@hrms.local');
        $remembered = $login->getCookie('hrms_remembered_employee')?->getValue();

        $this->post('/logout')->assertRedirect('/login');
        Auth::forgetGuards();

        $this->withCookie('hrms_remembered_employee', (string) $remembered)
            ->get('/login')
            ->assertOk()
            ->assertSee('value="hr.manager@hrms.local"', false)
            ->assertDontSee('value="HR-MGR-2026-0001"', false)
            ->assertDontSee('value="ChangeMe123!"', false);
    }

    public function test_remember_me_replaces_the_saved_identifier_with_the_one_typed_last(): void
    {
        $this->withCookie('hrms_remembered_employee', 'hr.manager@hrms.local')
            ->post('/login', [
                'employee_id' => 'HR-MGR-2026-0001',
                'password' => 'ChangeMe123!',
                'remember' => '1',
            ])
            ->assertCookie('hrms_remembered_employee', 'HR-MGR-2026-0001');
    }

    public function test_signing_in_without_remember_me_removes_a_previously_saved_employee_id(): void
    {
        $this->withCookie('hrms_remembered_employee', 'HR-MGR-2026-0001')
            ->post('/login', [
                'employee_id' => 'HR-MGR-2026-0001',
                'password' => 'ChangeMe123!',
                'remember' => '0',
            ])
            ->assertCookieExpired('hrms_remembered_employee');
    }

    public function test_legacy_persistent_auth_cookie_is_rejected_and_migrated_to_identity_only(): void
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
            ->assertCookie('hrms_remembered_employee', 'HR-MGR-2026-0001');

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

    private function userForEmployee(string $employeeNumber): User
    {
        return User::query()
            ->whereHas('employee', fn ($query) => $query->where('employee_number', $employeeNumber))
            ->firstOrFail();
    }
}
