<?php

namespace Tests\Feature\Pwa;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The device app lock is a browser-only feature, so most of it cannot be
 * exercised from PHPUnit. What CAN be asserted here is the part that actually
 * matters and that a future edit could quietly break: that the PIN and the
 * fingerprint never acquire a path to the server.
 *
 * The rest of the class covers the rendering contract the JavaScript depends
 * on — if a hook attribute is renamed in Blade, the feature silently stops
 * appearing on phones with no error anywhere, which is exactly the kind of
 * failure a test should catch instead of a user.
 */
class DeviceLockTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsEmployee(): User
    {
        $this->seed();

        return User::query()->where('email', 'employee@hrms.local')->firstOrFail();
    }

    public function test_the_unlock_overlay_renders_hidden_and_carries_no_pin_material(): void
    {
        $user = $this->actingAsEmployee();

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        // Present on every authenticated page, but inert until app-lock.js
        // decides the device qualifies.
        $response->assertSee('data-app-lock', false);
        $response->assertSee('class="app-lock"', false);

        $html = $response->getContent();

        // `hidden` on the overlay element itself. Without it, a browser with
        // JavaScript disabled would paint a full-screen lock over the app.
        $this->assertMatchesRegularExpression(
            '/<div\s+class="app-lock".*?hidden\s*>/s',
            $html,
            'The lock overlay must render with the hidden attribute so it can never cover the app before app-lock.js runs.',
        );

        // The only server value it is allowed to carry is the account id used
        // to namespace this device's local record.
        $this->assertStringContainsString('data-user-key="'.$user->id.'"', $html);
    }

    public function test_the_settings_panel_renders_unavailable_by_default(): void
    {
        $user = $this->actingAsEmployee();

        $response = $this->actingAs($user)->get(route('settings.edit'))->assertOk();

        $response->assertSee('data-device-lock-panel', false);
        $response->assertSee('data-device-lock-nav', false);

        // Both start unavailable and are only revealed by app-lock.js on a
        // touch device. A desktop must never see either.
        $response->assertSee('data-device-lock-panel data-available="false"', false);
        $response->assertSee('data-device-lock-nav data-available="false"', false);

        // The setup hooks the script binds to.
        foreach ([
            'data-device-lock-step="create"',
            'data-device-lock-step="confirm"',
            'data-device-lock-step="biometric"',
            'data-device-lock-step="enrolled"',
            'data-device-lock-pin-input',
            'data-device-lock-autolock',
            // The standing "add fingerprint" offer shown when a PIN is set but
            // the sensor was skipped. Without it, someone who taps "Not now"
            // during setup has no obvious way back to it.
            'data-device-lock-nudge',
        ] as $hook) {
            $response->assertSee($hook, false);
        }
    }

    public function test_the_pin_panel_contains_no_form_and_no_submit_target(): void
    {
        $markup = file_get_contents(resource_path('views/settings/partials/device-lock.blade.php'));

        // A <form> here is the single edit that would turn a device-local
        // secret into a server-side one, so it is asserted against directly.
        $this->assertStringNotContainsString('<form', $markup, 'The app lock panel must never submit anything.');
        $this->assertStringNotContainsString('@csrf', $markup);

        // A real `action` attribute, not the panel's own data-device-lock-action
        // hooks, which would otherwise match a naive substring search.
        $this->assertDoesNotMatchRegularExpression(
            '/\saction\s*=/',
            $markup,
            'The app lock panel must have no submit target.',
        );
    }

    public function test_the_lock_script_never_transmits_the_pin_or_the_credential(): void
    {
        $script = file_get_contents(resource_path('js/app-lock.js'));

        /*
         * The privacy guarantee in one assertion. The PIN digest and the
         * WebAuthn credential id live in localStorage; if any of these
         * appeared, they would have a route off the device.
         *
         * `signOut()` posts a generated form to the logout route, which is why
         * a bare "form.submit" is not on this list — but it carries only the
         * CSRF token, and the assertions below pin that down.
         */
        foreach (['fetch(', 'XMLHttpRequest', 'sendBeacon', 'WebSocket', 'EventSource', 'axios'] as $transport) {
            $this->assertStringNotContainsString(
                $transport,
                $script,
                "app-lock.js must not use [{$transport}]: the PIN and the fingerprint credential never leave the device.",
            );
        }

        // The only outbound navigation is the sign-out, and the form it builds
        // carries exactly one field: the CSRF token.
        $this->assertStringContainsString("token.name = '_token';", $script);
        $this->assertSame(
            1,
            substr_count($script, 'form.append('),
            'The sign-out form must carry the CSRF token and nothing else.',
        );

        // The digest is derived, never the PIN stored.
        $this->assertStringContainsString('PBKDF2', $script);
        $this->assertStringContainsString('deriveBits', $script);
        $this->assertStringContainsString("userVerification: 'required'", $script);

        // 'platform' is what binds the credential to this handset rather than a
        // roaming key that could be carried to another device.
        $this->assertStringContainsString("authenticatorAttachment: 'platform'", $script);
    }

    public function test_no_route_exists_that_could_accept_a_pin_or_a_biometric_credential(): void
    {
        $suspicious = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            // The existing local-only scanner simulator is a different feature:
            // it creates device events and stores no biometric data either.
            ->reject(fn (string $uri) => str_contains($uri, 'biometric-simulator'))
            ->filter(fn (string $uri) => str_contains($uri, 'pin')
                || str_contains($uri, 'device-lock')
                || str_contains($uri, 'app-lock')
                || str_contains($uri, 'webauthn')
                || str_contains($uri, 'passkey'))
            ->values();

        $this->assertTrue(
            $suspicious->isEmpty(),
            'The device app lock must have no server endpoint. Found: '.$suspicious->implode(', '),
        );
    }

    public function test_no_migration_stores_a_pin_or_a_biometric_credential(): void
    {
        /*
         * Deliberately specific names. A bare 'fingerprint' would match the
         * schedule_recommendations dedup hash column, which has nothing to do
         * with a biometric — and a test that cries wolf gets deleted.
         */
        $columns = [
            'pin_hash',
            'device_pin',
            'lock_pin',
            'device_lock',
            'webauthn',
            'biometric_credential',
            'fingerprint_template',
        ];

        foreach (glob(database_path('migrations/*.php')) as $migration) {
            $contents = file_get_contents($migration);

            foreach ($columns as $column) {
                $this->assertStringNotContainsString(
                    $column,
                    $contents,
                    "[{$column}] appears in ".basename($migration).'. The app lock is device-local and must never gain a database column.',
                );
            }
        }
    }
}
