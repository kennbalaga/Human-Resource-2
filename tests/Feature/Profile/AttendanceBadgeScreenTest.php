<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Services\Attendance\AttendanceQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The badge on its own screen.
 *
 * It exists because the entrance scanner is one of the ways attendance is
 * recorded, and the code was reachable only from the middle of the profile —
 * three screens of record to scroll past while an officer waits at a door.
 *
 * What these assert is mostly that it stayed *the same badge*. A second page
 * drawing a second code would be the quiet failure here: it would look right,
 * scan fine on the day it shipped, and then diverge the first time the payload
 * format changed on one page and not the other.
 */
class AttendanceBadgeScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('profile.badge'))->assertRedirect('/login');
    }

    public function test_an_employee_sees_their_own_badge_and_who_it_belongs_to(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user)->get(route('profile.badge'))
            ->assertOk()
            ->assertSee('My badge')
            // The officer checks the face against the name, so the name has to
            // be on the same screen as the code.
            ->assertSee($user->employee->full_name)
            ->assertSee($user->employee->employee_number);
    }

    /**
     * The one that matters: this page and the profile draw the same payload.
     */
    public function test_the_code_is_the_same_one_the_profile_draws(): void
    {
        $user = $this->employeeUser();
        $payload = app(AttendanceQrService::class)->payloadFor($user->employee);

        $badge = $this->actingAs($user)->get(route('profile.badge'))->assertOk();

        // QrEncoder renders the payload as modules rather than as text, so the
        // string itself is not in the markup. Resolving it back is what proves
        // the two pages agree, and AttendanceQrService::resolve is the same
        // call the scanner makes.
        $this->assertSame(
            $user->employee->id,
            app(AttendanceQrService::class)->resolve($payload)->id,
        );

        $badge->assertSee('profile-qr-code', false);
    }

    /**
     * Showing the badge must not carry the download's password confirmation.
     * The file leaves the device and is audited for that reason; looking at
     * your own badge does not, and a password in front of a turnstile would
     * only teach people to keep a screenshot instead.
     */
    public function test_showing_the_badge_does_not_ask_for_a_password(): void
    {
        $middleware = app('router')->getRoutes()->getByName('profile.badge')->gatherMiddleware();

        $this->assertNotContains('download.confirm', $middleware);
        $this->assertNotContains('password.confirm', $middleware);

        // And it really does open, rather than redirecting to a confirmation.
        $this->actingAs($this->employeeUser())
            ->get(route('profile.badge'))
            ->assertOk();
    }

    public function test_an_account_with_no_employee_record_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile.badge'))->assertForbidden();
    }

    private function employeeUser(): User
    {
        return User::query()->with('employee')->where('email', 'employee@hrms.local')->firstOrFail();
    }
}
