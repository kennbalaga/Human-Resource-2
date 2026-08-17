<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureSingleActiveSession;
use App\Models\User;
use App\Services\ActiveDeviceSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An account may be signed in on one device at a time. The newest sign-in
 * wins; the device holding the older session is signed out the next time it
 * speaks to the server.
 */
class SingleActiveSessionTest extends TestCase
{
    use RefreshDatabase;

    private const CREDENTIALS = [
        'employee_id' => 'HR-2026-0001',
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
        $this->assertSame($token, $this->userForEmployee('HR-2026-0001')->active_session_token);
    }

    public function test_signing_in_again_elsewhere_moves_the_claim_to_the_newer_device(): void
    {
        $this->post('/login', self::CREDENTIALS);
        $firstDevice = $this->userForEmployee('HR-2026-0001')->active_session_token;

        // A second browser arrives with no session of its own.
        Auth::forgetGuards();
        $this->flushSession();
        $this->post('/login', self::CREDENTIALS)->assertRedirect('/dashboard');
        $secondDevice = $this->userForEmployee('HR-2026-0001')->active_session_token;

        $this->assertNotEmpty($secondDevice);
        $this->assertNotSame($firstDevice, $secondDevice);
        $this->assertSame(session(ActiveDeviceSessionService::SESSION_KEY), $secondDevice);
    }

    public function test_the_displaced_device_is_signed_out_on_its_next_request(): void
    {
        $this->post('/login', self::CREDENTIALS);

        $this->signInElsewhere();

        $this->get('/dashboard')
            ->assertRedirect('/login')
            ->assertSessionHas('notice', EnsureSingleActiveSession::MESSAGE);

        $this->assertGuest();
    }

    public function test_the_displaced_device_is_told_why_when_it_asks_over_json(): void
    {
        $this->post('/login', self::CREDENTIALS);

        $this->signInElsewhere();

        $this->getJson(route('session.keep-alive'))
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'signed_in_elsewhere')
            ->assertJsonPath('message', EnsureSingleActiveSession::MESSAGE)
            ->assertJsonPath('redirect', route('login'));

        $this->assertGuest();
    }

    public function test_an_account_holding_no_recorded_session_is_left_signed_in(): void
    {
        // What every session open at deploy time looks like: signed in before
        // the column existed, and no reason to be thrown out for it.
        $user = $this->userForEmployee('HR-2026-0001');
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
        $this->assertNull($this->userForEmployee('HR-2026-0001')->active_session_token);
    }

    public function test_a_displaced_device_signing_out_leaves_the_newer_claim_alone(): void
    {
        $this->post('/login', self::CREDENTIALS);
        $newerDevice = $this->signInElsewhere();

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertSame($newerDevice, $this->userForEmployee('HR-2026-0001')->active_session_token);
    }

    /**
     * Stand in for the same account signing in on a different device: the
     * account's token moves, and this device is left holding the old one.
     */
    private function signInElsewhere(): string
    {
        $token = Str::random(64);

        $this->userForEmployee('HR-2026-0001')
            ->forceFill(['active_session_token' => $token])
            ->save();

        // A real request builds the guard from scratch and reads the account
        // back from the database; the test guard would otherwise keep serving
        // the copy it loaded at sign-in, token and all.
        Auth::forgetGuards();

        return $token;
    }

    private function userForEmployee(string $employeeNumber): User
    {
        return User::query()
            ->whereHas('employee', fn ($query) => $query->where('employee_number', $employeeNumber))
            ->firstOrFail();
    }
}
