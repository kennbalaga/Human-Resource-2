<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaviconTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_and_authenticated_pages_use_the_same_favicon(): void
    {
        $this->seed();

        /*
         * Sign-in and the app behind it must show the same mark, because a tab
         * icon that changes at the sign-in boundary looks like a different
         * site — the exact instinct a phishing page relies on people ignoring.
         *
         * The cache-busting stamp is read off the page rather than written in
         * here, so bumping it in partials.favicon (which every icon swap has to
         * do) does not fail a test about consistency.
         */
        $login = $this->get(route('login'))->assertOk();

        $this->assertMatchesRegularExpression(
            '#'.preg_quote(asset('images/icons/favicon-32.png'), '#').'\?v=\d+#',
            $login->getContent(),
            'The sign-in page is not serving the shared favicon.',
        );

        preg_match(
            '#'.preg_quote(asset('images/icons/favicon-32.png'), '#').'\?v=\d+#',
            $login->getContent(),
            $matches,
        );

        $faviconUrl = $matches[0];

        $this->get(route('password.request'))->assertOk()->assertSee($faviconUrl, false);
        $this->get(route('password.reset', ['token' => 'preview-token']))->assertOk()->assertSee($faviconUrl, false);

        $user = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee($faviconUrl, false);
        $this->actingAs($user)->get(route('attendance.index'))->assertOk()->assertSee($faviconUrl, false);

        // Every size a browser may ask for, all derived from the same source.
        foreach ([16, 32, 48] as $size) {
            $path = public_path("images/icons/favicon-{$size}.png");

            $this->assertFileExists($path);
            $this->assertSame([$size, $size], array_slice(getimagesize($path), 0, 2));
        }
    }
}
