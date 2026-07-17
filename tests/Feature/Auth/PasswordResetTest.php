<?php

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_forgot_password_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('Reset Your Password');
    }

    public function test_active_employee_can_request_a_password_reset_link(): void
    {
        Notification::fake();
        $user = $this->userForEmployee('HR-0001');

        $response = $this->from('/forgot-password')->post('/forgot-password', [
            'employee_id' => 'hr-0001',
            'email' => $user->email,
        ]);

        $response->assertRedirect('/forgot-password')->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_unknown_or_mismatched_account_receives_the_same_generic_response(): void
    {
        Notification::fake();
        $user = $this->userForEmployee('HR-0001');

        $valid = $this->post('/forgot-password', [
            'employee_id' => 'HR-0001',
            'email' => $user->email,
        ]);

        $unknown = $this->post('/forgot-password', [
            'employee_id' => 'HR-9999',
            'email' => 'missing@example.test',
        ]);

        $this->assertSame($valid->getSession()->get('status'), $unknown->getSession()->get('status'));
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_inactive_employee_cannot_receive_a_reset_link(): void
    {
        Notification::fake();
        $user = $this->userForEmployee('HR-0001');
        $user->employee()->update(['employment_status' => 'inactive']);

        $this->post('/forgot-password', [
            'employee_id' => 'HR-0001',
            'email' => $user->email,
        ])->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_password_can_be_reset_and_existing_api_tokens_are_revoked(): void
    {
        $user = $this->userForEmployee('HR-0001');
        $originalRememberToken = $user->remember_token;
        $user->createToken('mobile');
        $token = Password::createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewSecurePass456!',
            'password_confirmation' => 'NewSecurePass456!',
        ]);

        $response->assertRedirect('/login')->assertSessionHas('status');
        $user->refresh();
        $this->assertTrue(Hash::check('NewSecurePass456!', $user->password));
        $this->assertNotSame($originalRememberToken, $user->remember_token);
        $this->assertCount(0, $user->tokens);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_reset_token_is_single_use(): void
    {
        $user = $this->userForEmployee('HR-0001');
        $token = Password::createToken($user);
        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewSecurePass456!',
            'password_confirmation' => 'NewSecurePass456!',
        ];

        $this->post('/reset-password', $payload)->assertRedirect('/login');

        $this->from('/reset-password/'.$token)->post('/reset-password', $payload)
            ->assertRedirect('/reset-password/'.$token)
            ->assertSessionHasErrors('email');
    }

    public function test_password_reset_does_not_bypass_or_remove_two_factor_authentication(): void
    {
        $user = $this->userForEmployee('HR-0001');
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $originalSecret = $user->two_factor_secret;
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewSecurePass456!',
            'password_confirmation' => 'NewSecurePass456!',
        ])->assertRedirect('/login');

        $user->refresh();
        $this->assertSame($originalSecret, $user->two_factor_secret);
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());

        $this->post('/login', [
            'employee_id' => 'HR-0001',
            'password' => 'NewSecurePass456!',
        ])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_reset_password_requires_the_strong_password_policy(): void
    {
        $user = $this->userForEmployee('HR-0001');
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'alllowercase12',
            'password_confirmation' => 'alllowercase12',
        ])->assertSessionHasErrors('password');
    }

    public function test_an_existing_browser_session_is_invalidated_after_the_password_changes(): void
    {
        $user = $this->userForEmployee('HR-0001');

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $user->update(['password' => 'ChangedOutsideThisSession456!']);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    private function userForEmployee(string $employeeNumber): User
    {
        return Employee::query()
            ->where('employee_number', $employeeNumber)
            ->firstOrFail()
            ->user()
            ->firstOrFail();
    }
}
