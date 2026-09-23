<?php

namespace Tests\Feature\Pwa;

use App\Models\TrustedMobileDevice;
use App\Models\User;
use App\Services\DeviceIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Tests\TestCase;

/**
 * The phone whose app lock stands in for the authenticator code.
 *
 * ---- What these tests are actually defending ----
 *
 * The trade only holds while both halves of the proof hold. A test suite that only
 * asserted the happy path would let each half be removed one at a time without
 * anything going red, and what would be left is "a phone skips two-factor" — which
 * is the thing this must never become. So every way the trust is supposed to end
 * has a test of its own: a wiped token, a different handset, a different account,
 * a lapsed trust, a removed lock, an administrator's reset.
 *
 * ---- Why the device cookie is pinned ----
 *
 * hrms_device is minted by the server and kept for a year, and the hash of it is
 * half the proof. Pinning a known value is how a test says "the same phone" or "a
 * different phone", which is the distinction under test.
 */
class TrustedMobileDeviceTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE_AGENT = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36';

    private const DESKTOP_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_an_enrolled_employee_is_challenged_on_a_phone_that_has_no_app_lock(): void
    {
        // The state everybody starts in: the app lock is set up from inside a
        // session, so the first sign-in on a handset cannot have one yet.
        $user = $this->enrolInTwoFactor($this->employee());

        $this->onPhone()->post('/login', [
            'employee_id' => 'employee@hrms.local',
            'password' => 'ChangeMe123!',
        ])->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $user->id);
    }

    public function test_a_phone_that_set_up_its_app_lock_signs_in_without_a_code(): void
    {
        $this->enrolInTwoFactor($this->employee());
        $token = $this->armThisPhone();

        $this->onPhone()->post('/login', [
            'employee_id' => 'employee@hrms.local',
            'password' => 'ChangeMe123!',
            'mobile_trust_tokens' => json_encode([$token]),
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_the_same_account_still_types_a_code_on_a_computer(): void
    {
        // The point of the trade is the lock in front of the app, and a computer
        // has none. Trust earned on a phone must not travel to a desk.
        $this->enrolInTwoFactor($this->employee());
        $token = $this->armThisPhone();

        $this->withHeader('User-Agent', self::DESKTOP_AGENT)
            ->withCookie(DeviceIdentityService::COOKIE, 'this-phone')
            ->post('/login', [
                'employee_id' => 'employee@hrms.local',
                'password' => 'ChangeMe123!',
                'mobile_trust_tokens' => json_encode([$token]),
            ])->assertRedirect(route('two-factor.login'));
    }

    public function test_a_wiped_app_lock_brings_the_code_back(): void
    {
        // Clearing site data, or uninstalling the app, destroys the token. The
        // cookie survives all of that, which is exactly why the token is required
        // alongside it: without this, a handset with no lock left on it would go
        // on skipping the code.
        $this->enrolInTwoFactor($this->employee());
        $this->armThisPhone();

        $this->onPhone()->post('/login', [
            'employee_id' => 'employee@hrms.local',
            'password' => 'ChangeMe123!',
        ])->assertRedirect(route('two-factor.login'));
    }

    public function test_the_token_is_worth_nothing_on_another_handset(): void
    {
        // The other half. A token read out of one phone's storage and replayed
        // from elsewhere matches no row, because the device hash does not match.
        $this->enrolInTwoFactor($this->employee());
        $token = $this->armThisPhone();

        $this->withHeader('User-Agent', self::PHONE_AGENT)
            ->withCookie(DeviceIdentityService::COOKIE, 'a-different-phone')
            ->post('/login', [
                'employee_id' => 'employee@hrms.local',
                'password' => 'ChangeMe123!',
                'mobile_trust_tokens' => json_encode([$token]),
            ])->assertRedirect(route('two-factor.login'));
    }

    public function test_one_persons_trust_does_not_cover_another_on_a_shared_handset(): void
    {
        $token = $this->armThisPhone();

        // A ward handset both of them have used. The manager is refused on a phone
        // outright, so the account tested here is a second enrolled employee.
        $colleague = $this->enrolInTwoFactor($this->account('hr.manager@hrms.local'));
        config(['security.mobile.restricted_roles' => []]);

        $this->onPhone()->post('/login', [
            'employee_id' => $colleague->email,
            'password' => 'ChangeMe123!',
            'mobile_trust_tokens' => json_encode([$token]),
        ])->assertRedirect(route('two-factor.login'));
    }

    public function test_a_forged_token_is_refused(): void
    {
        // The whole reason the flag is a server-issued token rather than the
        // browser simply saying "I have an app lock": anybody holding the password
        // could say that.
        $this->enrolInTwoFactor($this->employee());
        $this->armThisPhone();

        $this->onPhone()->post('/login', [
            'employee_id' => 'employee@hrms.local',
            'password' => 'ChangeMe123!',
            'mobile_trust_tokens' => json_encode([str_repeat('f', 64)]),
        ])->assertRedirect(route('two-factor.login'));
    }

    public function test_a_malformed_token_field_is_ignored_rather_than_fatal(): void
    {
        $this->enrolInTwoFactor($this->employee());

        foreach (['not json at all', '"a bare string"', '{"a":"map"}', '[123, null, {}]'] as $payload) {
            $this->onPhone()->post('/login', [
                'employee_id' => 'employee@hrms.local',
                'password' => 'ChangeMe123!',
                'mobile_trust_tokens' => $payload,
            ])->assertRedirect(route('two-factor.login'));
        }
    }

    public function test_a_handset_that_stopped_being_used_loses_its_trust(): void
    {
        $this->enrolInTwoFactor($this->employee());
        $token = $this->armThisPhone();

        // The lock is what normally ends a trust, and a phone in a drawer never
        // removes its lock. The clock is the only thing that will.
        TrustedMobileDevice::query()->update([
            'armed_at' => now()->subDays(400),
            'last_used_at' => now()->subDays(400),
        ]);

        $this->onPhone()->post('/login', [
            'employee_id' => 'employee@hrms.local',
            'password' => 'ChangeMe123!',
            'mobile_trust_tokens' => json_encode([$token]),
        ])->assertRedirect(route('two-factor.login'));
    }

    public function test_using_the_trust_keeps_it_alive(): void
    {
        $this->enrolInTwoFactor($this->employee());
        $token = $this->armThisPhone();

        TrustedMobileDevice::query()->update(['armed_at' => now()->subDays(100), 'last_used_at' => null]);

        $this->onPhone()->post('/login', [
            'employee_id' => 'employee@hrms.local',
            'password' => 'ChangeMe123!',
            'mobile_trust_tokens' => json_encode([$token]),
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertTrue(
            TrustedMobileDevice::query()->firstOrFail()->last_used_at->isToday(),
            'A sign-in must record that the handset is still in use, or a phone in daily use would expire.',
        );
    }

    /* ---------------- Arming and disarming ---------------- */

    public function test_arming_stores_a_digest_rather_than_the_token(): void
    {
        $token = $this->armThisPhone();

        $trust = TrustedMobileDevice::query()->firstOrFail();

        $this->assertNotSame($token, $trust->token_hash);
        $this->assertStringNotContainsString($token, $trust->token_hash);
        $this->assertSame(64, strlen((string) $trust->token_hash), 'The stored value should be a SHA-256 digest.');

        // And the device is recorded by hash too: holding the cookie's value is
        // the whole of what makes a browser this browser.
        $this->assertSame(hash('sha256', 'this-phone'), $trust->device_hash);
    }

    public function test_arming_twice_replaces_the_token_rather_than_adding_one(): void
    {
        $first = $this->armThisPhone();
        $second = $this->armThisPhone();

        $this->assertNotSame($first, $second);
        $this->assertSame(1, TrustedMobileDevice::query()->count());

        // Changing the PIN must not leave the previous token working.
        $this->enrolInTwoFactor($this->employee());

        $this->onPhone()->post('/login', [
            'employee_id' => 'employee@hrms.local',
            'password' => 'ChangeMe123!',
            'mobile_trust_tokens' => json_encode([$first]),
        ])->assertRedirect(route('two-factor.login'));
    }

    public function test_a_computer_cannot_ask_to_be_trusted(): void
    {
        $this->actingAs($this->employee())
            ->withCredentials()
            ->withHeader('User-Agent', self::DESKTOP_AGENT)
            ->postJson(route('trusted-mobile.store'))
            ->assertStatus(422);

        $this->assertSame(0, TrustedMobileDevice::query()->count());
    }

    public function test_a_restricted_role_cannot_ask_to_be_trusted(): void
    {
        // Unreachable in practice — the middleware turns them out first — but the
        // endpoint must not be the one place the rule is missing.
        config(['security.mobile.restricted_roles' => ['hr-manager']]);

        $this->actingAs($this->account('hr.manager@hrms.local'))
            ->withCredentials()
            ->withHeader('User-Agent', self::PHONE_AGENT)
            ->postJson(route('trusted-mobile.store'))
            ->assertForbidden();
    }

    public function test_a_guest_cannot_ask_to_be_trusted(): void
    {
        $this->onPhone()->postJson(route('trusted-mobile.store'))->assertUnauthorized();
        $this->assertSame(0, TrustedMobileDevice::query()->count());
    }

    public function test_removing_the_app_lock_withdraws_the_trust(): void
    {
        $token = $this->armThisPhone();
        $this->enrolInTwoFactor($this->employee());

        $this->actingAs($this->employee())
            ->onPhone()
            ->deleteJson(route('trusted-mobile.destroy'))
            ->assertOk();

        $this->assertSame(0, TrustedMobileDevice::query()->count());
        $this->signOut();

        $this->onPhone()->post('/login', [
            'employee_id' => 'employee@hrms.local',
            'password' => 'ChangeMe123!',
            'mobile_trust_tokens' => json_encode([$token]),
        ])->assertRedirect(route('two-factor.login'));
    }

    public function test_an_administrator_resetting_two_factor_withdraws_every_trusted_device(): void
    {
        // The reset is what is done when a phone is lost, and that handset is the
        // one device that must stop being able to skip the code.
        $employee = $this->enrolInTwoFactor($this->employee());
        $token = $this->armThisPhone();

        $this->actingAs($this->account('admin@hrms.local'))
            ->withCredentials()
            ->withHeader('User-Agent', self::DESKTOP_AGENT)
            ->post(route('employees.two-factor.reset', $employee->employee), [
                'current_password' => 'ChangeMe123!',
                'identity_verified' => '1',
            ])->assertRedirect();

        $this->assertSame(0, TrustedMobileDevice::query()->count());

        // Belt: with the enrollment gone there is no challenge to skip, so the
        // assertion that matters is the row, not the redirect.
        $this->assertFalse($employee->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertNotSame('', $token);
    }

    public function test_the_app_lock_itself_still_has_no_server_endpoint(): void
    {
        /*
         * The guarantee this feature was built around, restated against the routes
         * it added. The trust endpoint takes an empty body and answers with an
         * opaque token; nothing anywhere accepts a PIN, a digest, a salt or a
         * WebAuthn credential.
         */
        $suspicious = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->reject(fn (string $uri) => str_contains($uri, 'biometric-simulator'))
            ->filter(fn (string $uri) => str_contains($uri, 'pin')
                || str_contains($uri, 'device-lock')
                || str_contains($uri, 'app-lock')
                || str_contains($uri, 'webauthn')
                || str_contains($uri, 'passkey'))
            ->values();

        $this->assertTrue($suspicious->isEmpty(), 'Found: '.$suspicious->implode(', '));
    }

    public function test_the_sync_script_sends_no_pin_material(): void
    {
        $script = file_get_contents(resource_path('js/mobile-access.js'));

        /*
         * mobile-access.js is the one file in this feature that talks to the
         * network, so it is the one place the PIN could acquire a route off the
         * device. It reads the lock record only to ask whether one exists.
         */
        foreach (['PBKDF2', 'deriveBits', 'record.hash,', 'record.salt,', 'credentialId'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $script,
                "mobile-access.js must not handle [{$forbidden}]: the PIN and the fingerprint never leave the device.",
            );
        }

        // The arming request carries no body at all, which is the clearest possible
        // form of "nothing about the lock is sent".
        $this->assertStringContainsString('body: null', $script);
    }

    /* ---------------- Helpers ---------------- */

    /**
     * A request from the handset this suite calls "this phone".
     *
     * withCredentials() is load-bearing on the JSON calls, not decoration: the test
     * client sends no cookies with a JSON request without it, so the arming request
     * would arrive with no hrms_device cookie, the server would mint a fresh one,
     * and every request would hash to a different handset.
     */
    private function onPhone(): self
    {
        return $this
            ->withCredentials()
            ->withHeader('User-Agent', self::PHONE_AGENT)
            ->withCookie(DeviceIdentityService::COOKIE, 'this-phone');
    }

    /**
     * What app-lock.js triggers once a PIN has been saved: the employee, signed in
     * on this handset, tells the server a lock now exists and keeps the token.
     */
    private function armThisPhone(): string
    {
        $response = $this->actingAs($this->employee())
            ->onPhone()
            ->postJson(route('trusted-mobile.store'))
            ->assertOk();

        $this->signOut();

        return $response->json('token');
    }

    /**
     * Back to being a guest, the way the next launch of the installed app is.
     *
     * forgetGuards() is the part that is easy to leave out and quietly wrong:
     * actingAs() sets the user on a guard held in a container that survives between
     * requests in one test, so a POST to the logout route ends the session while the
     * guard goes on answering with the same user — and the sign-in under test is
     * then bounced to the dashboard by the `guest` middleware without ever running.
     */
    private function signOut(): void
    {
        $this->post(route('logout'));
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function enrolInTwoFactor(User $user): User
    {
        app(EnableTwoFactorAuthentication::class)($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $user->fresh();
    }

    private function employee(): User
    {
        return $this->account('employee@hrms.local');
    }

    private function account(string $email): User
    {
        return User::query()->with(['roles', 'employee'])->where('email', $email)->firstOrFail();
    }
}
