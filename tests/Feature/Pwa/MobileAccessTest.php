<?php

namespace Tests\Feature\Pwa;

use App\Models\Role;
use App\Models\User;
use App\Support\MobileDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The app is employee-only on a phone.
 *
 * The roles that can read the whole hospital's records are desk-bound: they are
 * refused at sign-in, turned out of a session that reaches the app from a handset,
 * and never offered the installable app in the first place. An employee, who only
 * ever sees their own roster, attendance and leave, is unaffected on every
 * device.
 *
 * Two halves are asserted here because the feature has two halves. The server
 * reads the user agent, which covers every phone and Android tablet; the browser
 * reads the pointer type, which is the only thing that can recognise an iPad,
 * since iPadOS sends a Mac's user agent byte for byte. A test that only covered
 * the first would pass while the second silently stopped being rendered.
 */
class MobileAccessTest extends TestCase
{
    use RefreshDatabase;

    /** A current Android Chrome string, which is what the ward handsets run. */
    private const PHONE_AGENT = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36';

    private const DESKTOP_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function restrictedAccounts(): array
    {
        return [
            'system administrator' => ['admin@hrms.local'],
            'HR manager' => ['hr.manager@hrms.local'],
            'department head' => ['nursing.head@hrms.local'],
        ];
    }

    #[DataProvider('restrictedAccounts')]
    public function test_a_restricted_role_is_refused_at_sign_in_on_a_phone(string $email): void
    {
        $response = $this->withHeader('User-Agent', self::PHONE_AGENT)->post('/login', [
            'employee_id' => $email,
            'password' => 'ChangeMe123!',
        ]);

        $response->assertRedirect('/')->assertSessionHasErrors('employee_id');
        $this->assertGuest();

        // The message has to name the account, not the password: somebody told
        // "these credentials do not match" on a phone resets a password that was
        // never wrong, and then calls IT.
        $this->assertStringContainsString(
            'cannot be used on a phone or tablet',
            (string) session('errors')->first('employee_id'),
        );
    }

    #[DataProvider('restrictedAccounts')]
    public function test_the_same_account_signs_in_normally_on_a_computer(string $email): void
    {
        $this->withHeader('User-Agent', self::DESKTOP_AGENT)->post('/login', [
            'employee_id' => $email,
            'password' => 'ChangeMe123!',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_an_employee_signs_in_on_a_phone(): void
    {
        $this->withHeader('User-Agent', self::PHONE_AGENT)->post('/login', [
            'employee_id' => 'employee@hrms.local',
            'password' => 'ChangeMe123!',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    /**
     * The case that prompted the rule: an employee installs the app on their own
     * handset, and an HR manager then tries to use it. The install is not what is
     * being refused — the account is.
     */
    public function test_an_hr_manager_cannot_sign_in_on_the_handset_an_employee_installed(): void
    {
        $agent = ['User-Agent' => self::PHONE_AGENT];

        $this->withHeaders($agent)->post('/login', [
            'employee_id' => 'employee@hrms.local',
            'password' => 'ChangeMe123!',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->post(route('logout'));

        $this->withHeaders($agent)->post('/login', [
            'employee_id' => 'hr.manager@hrms.local',
            'password' => 'ChangeMe123!',
        ])->assertSessionHasErrors('employee_id');

        $this->assertGuest();
    }

    /**
     * A session opened before the rule existed, or started on a computer whose
     * cookies were carried to a phone. The door is not the whole wall.
     */
    public function test_an_open_session_reaching_the_app_from_a_phone_is_signed_out(): void
    {
        $manager = $this->account('hr.manager@hrms.local');

        $this->actingAs($manager)
            ->withHeader('User-Agent', self::PHONE_AGENT)
            ->get(route('dashboard'))
            ->assertRedirect(route('mobile.unavailable'));

        // Refused, not merely hidden: a restricted account left signed in on a
        // handset is the thing the rule exists to prevent.
        $this->assertGuest();
    }

    public function test_a_json_caller_is_told_where_to_go_rather_than_silently_redirected(): void
    {
        $manager = $this->account('hr.manager@hrms.local');

        $this->actingAs($manager)
            ->withHeader('User-Agent', self::PHONE_AGENT)
            ->getJson(route('session.keep-alive'))
            ->assertForbidden()
            ->assertJsonPath('redirect', route('mobile.unavailable'));
    }

    public function test_an_employee_keeps_working_on_a_phone(): void
    {
        $this->actingAs($this->account('employee@hrms.local'))
            ->withHeader('User-Agent', self::PHONE_AGENT)
            ->get(route('dashboard'))
            ->assertOk();
    }

    /**
     * An account holding both roles is refused. Roles add reach rather than
     * average it out, and the wider one is what the phone would be carrying.
     */
    public function test_an_employee_who_is_also_an_hr_manager_is_refused(): void
    {
        $user = $this->account('employee@hrms.local');
        $user->roles()->attach(Role::query()->where('slug', 'hr-manager')->value('id'));

        $this->actingAs($user->fresh())
            ->withHeader('User-Agent', self::PHONE_AGENT)
            ->get(route('dashboard'))
            ->assertRedirect(route('mobile.unavailable'));
    }

    public function test_the_restriction_can_be_lifted_by_configuration(): void
    {
        // The escape hatch for testing an administrator account on a real handset,
        // and the reason the role list is not written into the code.
        config(['security.mobile.restricted_roles' => []]);

        $this->actingAs($this->account('admin@hrms.local'))
            ->withHeader('User-Agent', self::PHONE_AGENT)
            ->get(route('dashboard'))
            ->assertOk();
    }

    /**
     * The user-agent rule, against real strings rather than the two this suite
     * otherwise reuses.
     *
     * It is the hinge the whole restriction turns on: too narrow and a handset
     * walks straight through, too broad and a hospital desktop is locked out of
     * the app it is meant to be used from. Both directions are asserted, and so is
     * the one device it is known not to catch — an iPad asking for the desktop
     * site sends a Mac's string byte for byte, which is why mobile-access.js
     * exists and why a change here that "fixes" that case is almost certainly
     * catching Macs instead.
     */
    public function test_the_user_agent_rule_against_real_browsers(): void
    {
        $mobile = [
            'iPhone Safari' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
            'Android Chrome' => 'Mozilla/5.0 (Linux; Android 14; SM-A536E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36',
            'Firefox on an Android tablet' => 'Mozilla/5.0 (Android 13; Tablet; rv:129.0) Gecko/129.0 Firefox/129.0',
            'Samsung Internet' => 'Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36',
            'iPad in compatibility mode' => 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
            'an Android WebView' => 'Mozilla/5.0 (Linux; Android 12; Pixel 5 Build/SP1A) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/103.0.0.0 Mobile Safari/537.36',
        ];

        $desktop = [
            'Chrome on Windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'Edge on Windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 Edg/128.0.0.0',
            'Safari on macOS' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
            'Firefox on Linux' => 'Mozilla/5.0 (X11; Linux x86_64; rv:129.0) Gecko/20100101 Firefox/129.0',
        ];

        foreach ($mobile as $label => $agent) {
            $this->assertTrue(
                MobileDevice::is($this->requestWith(['HTTP_USER_AGENT' => $agent])),
                "{$label} must be recognised as a handset, or a restricted role walks straight in.",
            );
        }

        foreach ($desktop as $label => $agent) {
            $this->assertFalse(
                MobileDevice::is($this->requestWith(['HTTP_USER_AGENT' => $agent])),
                "{$label} must not be mistaken for a handset, or HR is locked out of the app entirely.",
            );
        }

        // Stated outright by Chromium, and trusted over the string beside it.
        $this->assertTrue(MobileDevice::is($this->requestWith([
            'HTTP_USER_AGENT' => $desktop['Chrome on Windows'],
            'HTTP_SEC_CH_UA_MOBILE' => '?1',
        ])));

        /*
         * '?0' is NOT taken as a denial: Chrome sends it on an Android tablet,
         * which this app must still treat as mobile. It falls through to the
         * string, which says "Tablet".
         */
        $this->assertTrue(MobileDevice::is($this->requestWith([
            'HTTP_USER_AGENT' => $mobile['Firefox on an Android tablet'],
            'HTTP_SEC_CH_UA_MOBILE' => '?0',
        ])));
    }

    /* ---------------- The installable app ---------------- */

    public function test_an_employee_page_advertises_the_installable_app(): void
    {
        $response = $this->actingAs($this->account('employee@hrms.local'))
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertSee('rel="manifest"', false);
        $response->assertSee('name="apple-mobile-web-app-capable"', false);
        $response->assertSee('name="sw-url"', false);
    }

    #[DataProvider('restrictedAccounts')]
    public function test_a_restricted_role_is_never_offered_the_installable_app(string $email): void
    {
        $response = $this->actingAs($this->account($email))
            ->get(route('dashboard'))
            ->assertOk();

        /*
         * The manifest is the only thing that actually stops an install.
         * Suppressing our own banner would leave Chromium's address-bar button and
         * its menu entry in place, and both read the manifest directly.
         */
        $response->assertDontSee('rel="manifest"', false);
        $response->assertDontSee('name="apple-mobile-web-app-capable"', false);

        // And the browser-side half, which is the only thing that can recognise an
        // iPad presenting itself as a Mac.
        $response->assertSee('name="mobile-restricted"', false);
    }

    public function test_an_employee_page_carries_no_restriction_marker(): void
    {
        $this->actingAs($this->account('employee@hrms.local'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('name="mobile-restricted"', false);
    }

    public function test_the_sign_in_page_advertises_no_installable_app(): void
    {
        // Nobody is identified yet, so an offer here would be an offer to the HR
        // manager about to type their password.
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('rel="manifest"', false);
    }

    /* ---------------- The refusal page ---------------- */

    public function test_the_refusal_page_reads_to_a_guest_and_blames_the_device_not_the_password(): void
    {
        $response = $this->get(route('mobile.unavailable'))->assertOk();

        $response->assertSee('Use a computer for this account');
        $response->assertSee('Nothing is wrong with your account.');
        $response->assertSee(route('login'), false);
    }

    public function test_the_refusal_page_returns_an_allowed_account_to_work(): void
    {
        // Arrived by typing the URL. Explaining a restriction that does not apply
        // is a small mystery for no reason.
        $this->actingAs($this->account('employee@hrms.local'))
            ->get(route('mobile.unavailable'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_the_browser_side_refusal_ends_the_session(): void
    {
        // What mobile-access.js posts when it sees a coarse pointer on an account
        // the user agent did not give away — an iPad.
        $this->actingAs($this->account('admin@hrms.local'))
            ->post(route('mobile.unavailable.store'))
            ->assertRedirect(route('mobile.unavailable'));

        $this->assertGuest();
    }

    private function requestWith(array $server): Request
    {
        return Request::create('/', 'GET', [], [], [], $server);
    }

    private function account(string $email): User
    {
        return User::query()->with('roles')->where('email', $email)->firstOrFail();
    }
}
