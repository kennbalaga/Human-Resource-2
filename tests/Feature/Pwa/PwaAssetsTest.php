<?php

namespace Tests\Feature\Pwa;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The PWA assets are static files served by the web server, not routes, so
 * these assert against the files on disk rather than issuing HTTP requests
 * Laravel's router would never handle.
 *
 * The service-worker assertions are the important half: they exist so a later
 * edit cannot quietly turn this into a worker that caches authenticated HTML.
 */
class PwaAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_is_valid_json_with_the_keys_an_install_prompt_needs(): void
    {
        $path = public_path('manifest.webmanifest');
        $this->assertFileExists($path);

        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach (['name', 'short_name', 'start_url', 'scope', 'display', 'theme_color', 'icons'] as $key) {
            $this->assertArrayHasKey($key, $manifest, "The manifest is missing [{$key}].");
        }

        $this->assertSame('standalone', $manifest['display']);
        $this->assertNotEmpty($manifest['icons']);

        // Relative so a subdirectory deployment (local dev runs under
        // /Human-Resource-2/public) resolves against the manifest's own URL
        // instead of the domain root.
        $this->assertStringStartsWith('./', $manifest['start_url']);
        $this->assertStringStartsWith('./', $manifest['scope']);
    }

    public function test_the_offline_fallback_page_exists_and_is_self_contained(): void
    {
        $path = public_path('offline.html');
        $this->assertFileExists($path);

        $html = file_get_contents($path);

        // It is served precisely when the network is gone, so it cannot depend
        // on a stylesheet or script request succeeding.
        $this->assertStringNotContainsString('<link rel="stylesheet"', $html);
        $this->assertStringNotContainsString('<script src=', $html);
        $this->assertStringContainsString('<style>', $html);
    }

    public function test_the_service_worker_never_caches_authenticated_html_or_the_api(): void
    {
        $path = public_path('sw.js');
        $this->assertFileExists($path);

        $worker = file_get_contents($path);

        // Mutations carry CSRF tokens and must reach the origin untouched.
        $this->assertStringContainsString("request.method !== 'GET'", $worker);

        // Navigations must be network-first with an offline fallback, never
        // read from or written to a cache. Compared with whitespace collapsed
        // so reformatting the file doesn't fail the test, but changing the
        // strategy does.
        $this->assertStringContainsString("request.mode === 'navigate'", $worker);
        $this->assertStringContainsString(
            'event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));',
            (string) preg_replace('/\s+/', ' ', $worker),
            'Navigations must be served from the network with the offline page as the only fallback.',
        );

        // The API is excluded explicitly rather than relying on the asset
        // extension check to happen to miss it.
        $this->assertStringContainsString("pathname.includes('/api/')", $worker);
        $this->assertStringContainsString('isNeverCached', $worker);
    }

    public function test_the_layout_advertises_the_manifest_and_service_worker(): void
    {
        $this->seed();

        $response = $this->actingAs(User::query()->where('email', 'employee@hrms.local')->firstOrFail())
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertSee('rel="manifest"', false);
        $response->assertSee('name="sw-url"', false);
        $response->assertSee('name="apple-mobile-web-app-capable"', false);
    }
}
