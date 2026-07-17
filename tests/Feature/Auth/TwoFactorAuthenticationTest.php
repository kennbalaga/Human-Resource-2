<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_employee_can_start_and_confirm_authenticator_setup(): void
    {
        $user = $this->userForEmployee('HR-0002');

        $this->actingAs($user)->post(route('two-factor.settings.enable'), [
            'current_password' => 'ChangeMe123!',
        ])->assertRedirect(route('settings.edit').'#two-factor');

        $pending = $user->fresh();
        $this->assertNotNull($pending->two_factor_secret);
        $this->assertNull($pending->two_factor_confirmed_at);

        $this->actingAs($pending)->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Scan this QR code')
            ->assertSee('Setup pending');

        $this->actingAs($pending)->post(route('two-factor.settings.confirm'), [
            'current_password' => 'ChangeMe123!',
            'code' => $this->currentCode($pending),
        ])->assertRedirect(route('settings.edit').'#two-factor')
            ->assertSessionHas('two_factor_recovery_codes');

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_enabled_account_must_complete_challenge_after_password(): void
    {
        $user = $this->enableTwoFactor($this->userForEmployee('HR-0002'));
        $recoveryCode = $user->recoveryCodes()[0];

        $this->post('/login', [
            'employee_id' => 'HR-0002',
            'password' => 'ChangeMe123!',
        ])->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $user->id);

        $this->assertGuest();

        $this->post(route('two-factor.login.store'), [
            'recovery_code' => $recoveryCode,
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotContains($recoveryCode, $user->fresh()->recoveryCodes());
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_invalid_challenge_does_not_authenticate_user(): void
    {
        $user = $this->enableTwoFactor($this->userForEmployee('HR-0002'));

        $this->withSession(['login.id' => $user->id])->post(route('two-factor.login.store'), [
            'code' => '000000',
        ])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_pending_enrollment_using_legacy_string_encryption_is_repaired(): void
    {
        $user = $this->userForEmployee('HR-0002');
        $secret = app(Google2FA::class)->generateSecretKey(32);
        $recoveryCodes = json_encode(['legacy-code-one', 'legacy-code-two'], JSON_THROW_ON_ERROR);

        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encryptString($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encryptString($recoveryCodes),
        ])->save();

        $this->actingAs($user)->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('Scan this QR code');

        $user->refresh();
        $this->assertSame($secret, Fortify::currentEncrypter()->decrypt($user->two_factor_secret));
        $this->assertSame($recoveryCodes, Fortify::currentEncrypter()->decrypt($user->two_factor_recovery_codes));
    }

    public function test_unreadable_unconfirmed_enrollment_is_safely_reset_instead_of_crashing_settings(): void
    {
        $user = $this->userForEmployee('HR-0002');
        $user->forceFill([
            'two_factor_secret' => 'belongs-to-another-application-key',
            'two_factor_recovery_codes' => 'unreadable-recovery-codes',
        ])->save();

        $this->actingAs($user)->get(route('settings.edit'))
            ->assertOk()
            ->assertSee('could not be decrypted and was safely reset')
            ->assertSee('Start secure setup');

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function test_unreadable_confirmed_enrollment_fails_closed_without_a_server_error(): void
    {
        $user = $this->userForEmployee('HR-0002');
        $user->forceFill([
            'two_factor_secret' => 'unreadable-confirmed-secret',
            'two_factor_recovery_codes' => 'unreadable-confirmed-recovery-codes',
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post('/login', [
            'employee_id' => 'HR-0002',
            'password' => 'ChangeMe123!',
        ])->assertRedirect(route('two-factor.login'));

        $this->post(route('two-factor.login.store'), ['code' => '123456'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_privileged_role_is_redirected_to_enrollment_until_protected(): void
    {
        config(['security.two_factor.required_roles' => ['hr-manager']]);
        $manager = $this->userForEmployee('HR-0001');

        $this->actingAs($manager)->get('/dashboard')
            ->assertRedirect(route('settings.edit').'#two-factor')
            ->assertSessionHas('two_factor_required');

        $this->actingAs($manager)->get(route('settings.edit'))->assertOk();
    }

    public function test_api_token_requires_second_factor_when_account_has_it_enabled(): void
    {
        $user = $this->enableTwoFactor($this->userForEmployee('HR-0002'));
        $payload = [
            'employee_id' => 'HR-0002',
            'password' => 'ChangeMe123!',
            'device_name' => '2FA test client',
        ];

        $this->postJson('/api/v1/auth/token', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('two_factor_code');

        $this->postJson('/api/v1/auth/token', $payload + [
            'two_factor_code' => $this->currentCode($user),
        ])->assertCreated();

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_existing_privileged_api_access_is_blocked_until_two_factor_enrollment(): void
    {
        config(['security.two_factor.required_roles' => ['hr-manager']]);
        $manager = $this->userForEmployee('HR-0001');
        Sanctum::actingAs($manager, ['workforce:read']);

        $this->getJson('/api/v1/employees')
            ->assertForbidden()
            ->assertJsonPath('message', 'Two-factor authentication enrollment is required for this privileged account.');
    }

    public function test_system_administrator_can_reset_another_users_two_factor_after_identity_verification(): void
    {
        $administrator = $this->userForEmployee('SYS-0001');
        $target = $this->enableTwoFactor($this->userForEmployee('HR-0001'));
        $target->createToken('existing device');

        $this->actingAs($administrator)->post(route('employees.two-factor.reset', $target->employee), [
            'current_password' => 'ChangeMe123!',
            'identity_verified' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $target->refresh();
        $this->assertNull($target->two_factor_secret);
        $this->assertNull($target->two_factor_recovery_codes);
        $this->assertNull($target->two_factor_confirmed_at);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $target->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $administrator->id,
            'route_name' => 'employees.two-factor.reset',
        ]);
    }

    private function enableTwoFactor(User $user): User
    {
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user->fresh();
    }

    private function currentCode(User $user): string
    {
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    private function userForEmployee(string $employeeNumber): User
    {
        return User::query()->with('employee')
            ->whereHas('employee', fn ($query) => $query->where('employee_number', $employeeNumber))
            ->firstOrFail();
    }
}
