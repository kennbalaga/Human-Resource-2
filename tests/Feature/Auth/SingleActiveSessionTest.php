<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\ActiveDeviceSessionService;
use App\Support\SessionNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\TestCase;

/**
 * An account may be signed in on one device at a time. A second device does
 * not take the account over: it closes it. The device that was already working
 * is signed out the next time it speaks to the server, the arriving sign-in is
 * turned away, and each side is told which of the two it is.
 */
class SingleActiveSessionTest extends TestCase
{
    use RefreshDatabase;

    private const CREDENTIALS = [
        'employee_id' => 'HR-MGR-2026-0001',
        'password' => 'ChangeMe123!',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_signing_in_records_the_session_the_account_is_held_on(): void
    {
        $this->post('/login', self::CREDENTIALS)->assertRedirect('/dashboard');

        $token = session(ActiveDeviceSessionService::SESSION_KEY);

        $this->assertNotEmpty($token);
        $this->assertSame($token, $this->userForEmployee('HR-MGR-2026-0001')->active_session_token);
    }

    public function test_signing_in_while_the_account_is_open_elsewhere_is_turned_away(): void
    {
        $this->openTheAccountOnAnotherDevice();

        $this->post('/login', self::CREDENTIALS)
            ->assertRedirect('/login')
            ->assertSessionHas(
                SessionNotice::FLASH_KEY,
                SessionNotice::AlreadyOpenElsewhere->value,
            );

        $this->assertGuest();
    }

    public function test_a_turned_away_sign_in_closes_the_account_on_the_device_that_had_it(): void
    {
        $this->openTheAccountOnAnotherDevice();

        $this->post('/login', self::CREDENTIALS);

        // Nothing is left holding the account, which is what signs the other
        // device out the next time it asks the server for anything.
        $this->assertNull($this->userForEmployee('HR-MGR-2026-0001')->active_session_token);
    }

    public function test_completing_the_two_factor_challenge_is_turned_away_the_same_way(): void
    {
        $user = $this->enableTwoFactor($this->userForEmployee('HR-OFFICER-2026-0001'));
        $recoveryCode = $user->recoveryCodes()[0];

        $this->post('/login', [
            'employee_id' => 'HR-OFFICER-2026-0001',
            'password' => 'ChangeMe123!',
        ])->assertRedirect(route('two-factor.login'));

        $user->forceFill(['active_session_token' => Str::random(64)])->save();

        $this->post(route('two-factor.login.store'), ['recovery_code' => $recoveryCode])
            ->assertRedirect('/login')
            ->assertSessionHas(
                SessionNotice::FLASH_KEY,
                SessionNotice::AlreadyOpenElsewhere->value,
            );

        $this->assertGuest();
        $this->assertNull($user->fresh()->active_session_token);
    }

    public function test_the_device_that_held_the_account_is_signed_out_on_its_next_request(): void
    {
        $this->post('/login', self::CREDENTIALS);

        $this->signInAttemptElsewhere();

        $this->get('/dashboard')
            ->assertRedirect('/login')
            ->assertSessionHas(
                SessionNotice::FLASH_KEY,
                SessionNotice::SignedInElsewhere->value,
            );

        $this->assertGuest();
    }

    public function test_the_device_that_held_the_account_is_told_why_when_it_asks_over_json(): void
    {
        $this->post('/login', self::CREDENTIALS);

        $this->signInAttemptElsewhere();

        $this->getJson(route('session.keep-alive'))
            ->assertUnauthorized()
            ->assertJsonPath('reason', SessionNotice::SignedInElsewhere->value)
            ->assertJsonPath('title', SessionNotice::SignedInElsewhere->title())
            ->assertJsonPath('message', SessionNotice::SignedInElsewhere->message())
            // Carried in the address because the session that would have held
            // a flashed message is the one being thrown away.
            ->assertJsonPath('redirect', route('login', ['reason' => SessionNotice::SignedInElsewhere->value]));

        $this->assertGuest();
    }

    public function test_the_login_page_explains_the_session_that_just_ended(): void
    {
        $this->withSession([
            SessionNotice::FLASH_KEY => SessionNotice::AlreadyOpenElsewhere->value,
        ])->get('/login')
            ->assertOk()
            ->assertSee(SessionNotice::AlreadyOpenElsewhere->title())
            ->assertSee(SessionNotice::AlreadyOpenElsewhere->message())
            ->assertSee('data-auth-dialog', false);
    }

    public function test_the_login_page_explains_a_reason_carried_in_the_address(): void
    {
        $this->get(route('login', ['reason' => SessionNotice::SignedInElsewhere->value]))
            ->assertOk()
            ->assertSee(SessionNotice::SignedInElsewhere->title())
            ->assertSee('data-auth-dialog', false);
    }

    public function test_the_login_page_ignores_a_reason_it_did_not_write(): void
    {
        $this->get(route('login', ['reason' => 'Your account was closed. Call 0900-555-0199.']))
            ->assertOk()
            ->assertDontSee('0900-555-0199')
            ->assertDontSee('data-auth-dialog', false);
    }

    public function test_both_sides_can_sign_in_again_once_the_account_is_closed(): void
    {
        $this->openTheAccountOnAnotherDevice();

        $this->post('/login', self::CREDENTIALS)->assertRedirect('/login');

        // The collision clears the account rather than locking it, so the very
        // next attempt — from either device — is an ordinary sign-in.
        $this->post('/login', self::CREDENTIALS)->assertRedirect('/dashboard');

        $this->assertAuthenticated();
    }

    public function test_an_account_holding_no_recorded_session_is_left_signed_in(): void
    {
        // What every session open at deploy time looks like: signed in before
        // the column existed, and no reason to be thrown out for it.
        $user = $this->userForEmployee('HR-MGR-2026-0001');
        $user->forceFill(['active_session_token' => null])->save();

        $this->actingAs($user)
            ->getJson(route('session.keep-alive'))
            ->assertOk();
    }

    public function test_signing_out_releases_the_claim(): void
    {
        $this->post('/login', self::CREDENTIALS);

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNull($this->userForEmployee('HR-MGR-2026-0001')->active_session_token);
    }

    public function test_a_device_whose_hold_ended_leaves_a_newer_claim_alone(): void
    {
        $this->post('/login', self::CREDENTIALS);
        $newerDevice = $this->signInAttemptElsewhere();

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame($newerDevice, $this->userForEmployee('HR-MGR-2026-0001')->active_session_token);
    }

    /**
     * Stand in for the account already being open on a device this test is not
     * driving: something else holds the slot, and this browser holds nothing.
     */
    private function openTheAccountOnAnotherDevice(): string
    {
        $token = Str::random(64);

        $this->userForEmployee('HR-MGR-2026-0001')
            ->forceFill(['active_session_token' => $token])
            ->save();

        return $token;
    }

    /**
     * Stand in for a sign-in attempt from another device landing while this
     * one is signed in: the slot stops being this device's, and this device is
     * left holding a token that no longer stands for anything.
     */
    private function signInAttemptElsewhere(): string
    {
        $token = $this->openTheAccountOnAnotherDevice();

        // A real request builds the guard from scratch and reads the account
        // back from the database; the test guard would otherwise keep serving
        // the copy it loaded at sign-in, token and all.
        Auth::forgetGuards();

        return $token;
    }

    private function enableTwoFactor(User $user): User
    {
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user->fresh();
    }

    private function userForEmployee(string $employeeNumber): User
    {
        return User::query()
            ->whereHas('employee', fn ($query) => $query->where('employee_number', $employeeNumber))
            ->firstOrFail();
    }
}
