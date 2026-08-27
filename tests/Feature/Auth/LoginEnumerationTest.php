<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sign-in must cost the same whether or not the account exists.
 *
 * Employee numbers here run in sequence (HR-OFFICER-2026-0001, -0002, …), so an
 * attacker does not have to guess them — they can count. What they must not
 * also learn is which of those numbers is real, and the password hash is what
 * gives that away: bcrypt dominates the cost of this request, so skipping it
 * for an unknown account makes that reply measurably quicker.
 *
 * Timing itself is too flaky to assert in a test suite, so what is asserted is
 * the cause: the hash comparison happens on every attempt.
 */
class LoginEnumerationTest extends TestCase
{
    use RefreshDatabase;

    private const WRONG_PASSWORD = 'definitely-not-the-password-1!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_an_unknown_employee_number_is_still_hashed(): void
    {
        Hash::spy();

        $this->post('/login', [
            'employee_id' => 'NUR-STAFF-DERM-2099-9999',
            'password' => self::WRONG_PASSWORD,
        ])->assertSessionHasErrors('employee_id');

        // The point of the whole fix: no short circuit past bcrypt.
        Hash::shouldHaveReceived('check')->once();
    }

    public function test_a_suspended_account_is_still_hashed(): void
    {
        $user = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $user->forceFill(['is_active' => false])->save();

        Hash::spy();

        $this->post('/login', [
            'employee_id' => $user->employee->employee_number,
            'password' => self::WRONG_PASSWORD,
        ])->assertSessionHasErrors('employee_id');

        Hash::shouldHaveReceived('check')->once();
    }

    public function test_a_real_and_an_unknown_account_fail_identically(): void
    {
        $real = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $realAttempt = $this->post('/login', [
            'employee_id' => $real->employee->employee_number,
            'password' => self::WRONG_PASSWORD,
        ]);

        $this->flushSession();

        $unknownAttempt = $this->post('/login', [
            'employee_id' => 'NUR-STAFF-DERM-2099-9999',
            'password' => self::WRONG_PASSWORD,
        ]);

        $this->assertSame($realAttempt->getStatusCode(), $unknownAttempt->getStatusCode());

        $message = fn ($response) => data_get(
            session()->get('errors')?->getBag('default')->get('employee_id'),
            0,
        );

        $realAttempt->assertSessionHasErrors('employee_id');
        $unknownAttempt->assertSessionHasErrors('employee_id');

        // Same wording either way: "no such employee" and "wrong password" must
        // not be distinguishable from the reply any more than from its timing.
        $this->assertSame(
            trans('auth.failed'),
            $message($unknownAttempt),
        );
    }

    public function test_the_api_token_endpoint_also_hashes_an_unknown_account(): void
    {
        Sanctum::actingAs(new User, []);
        app('auth')->forgetGuards();

        Hash::spy();

        $this->postJson('/api/v1/auth/token', [
            'employee_id' => 'NUR-STAFF-DERM-2099-9999',
            'password' => self::WRONG_PASSWORD,
            'device_name' => 'enumeration probe',
        ])->assertStatus(422);

        Hash::shouldHaveReceived('check')->once();
    }
}
