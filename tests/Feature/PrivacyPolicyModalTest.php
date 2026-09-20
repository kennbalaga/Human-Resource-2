<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The notice is now read in two places: the standalone page, and a modal the
 * auth pages open over the sign-in form.
 *
 * What these guard is that the two cannot drift apart, and that the page did
 * not quietly stop being reachable on its own — a privacy notice that exists
 * only inside a dialog cannot be printed, bookmarked, or sent to anybody.
 */
class PrivacyPolicyModalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function authPages(): array
    {
        return [
            'login' => ['login'],
            'forgot password' => ['password.request'],
            'reset password' => ['password.reset'],
        ];
    }

    private function url(string $name): string
    {
        return $name === 'password.reset'
            ? route($name, ['token' => 'preview-token'])
            : route($name);
    }

    private function plainUrl(): string
    {
        return route('privacy-policy', ['plain' => 1]);
    }

    /** The notice's own wording wraps across lines in the source. */
    private function flattened(string $html): string
    {
        return Str::squish($html);
    }

    #[DataProvider('authPages')]
    public function test_every_auth_page_carries_the_notice_in_a_dialog(string $page): void
    {
        $response = $this->get($this->url($page))->assertOk();

        $response
            ->assertSee('data-privacy-modal', false)
            // The trigger is still a link to the real page, so it survives a
            // middle click and a browser with JavaScript switched off.
            ->assertSee('href="'.route('privacy-policy').'" class="privacy" data-privacy-open', false);

        // Not a teaser or a summary: the whole notice is in the dialog.
        $html = $this->flattened($response->getContent());

        foreach ([
            'Republic Act No. 10173',
            'Right to data portability',
            'no fingerprint or facial template is stored in this application',
            'Data Protection Officer',
        ] as $line) {
            $this->assertStringContainsString($line, $html, "The dialog on {$page} is missing: {$line}");
        }
    }

    public function test_the_modal_keeps_a_way_out_to_the_page_itself(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Open the full page')
            ->assertSee('class="privacy-modal-full" target="_blank"', false);
    }

    /**
     * The two-factor page opts out of script.js, which is the whole reason the
     * modal's behaviour is its own entry. If it ever rides along in that
     * bundle again, the link on that one page goes back to sending the reader
     * away mid-challenge.
     */
    public function test_the_page_that_skips_the_shared_script_still_gets_the_modal(): void
    {
        $shell = $this->blade('<x-auth-shell title="Two-factor" :script="false">form</x-auth-shell>');

        $shell->assertSee('data-privacy-modal', false);

        // Matched by pattern, not by path: the dev server serves these by
        // source path and a build serves them hashed, and this should hold
        // either way rather than only when `npm run dev` happens to be up.
        $html = (string) $shell;

        $this->assertMatchesRegularExpression('~<script[^>]+src="[^"]*privacy-modal[^"]*\.js"~', $html);
        $this->assertDoesNotMatchRegularExpression('~<script[^>]+src="[^"]*/script[.-][^"]*\.js"~', $html);
    }

    public function test_the_notice_says_the_same_thing_in_both_places(): void
    {
        $modal = $this->flattened($this->get(route('login'))->assertOk()->getContent());
        $page = $this->flattened($this->get($this->plainUrl())->assertOk()->getContent());

        // Sampled across the whole document rather than at its top: the two
        // include the same partials, and this fails the moment one of them is
        // given a copy of the text of its own.
        foreach ([
            'Right to erasure or blocking',
            'no fingerprint or facial template is stored in this application',
            'never sold, rented, or used for advertising',
            'aggregate figures and pseudonymous records',
            'These requests are handled by people, not by a button in this app.',
            'automatic deletion is not yet switched on',
        ] as $line) {
            $this->assertStringContainsString($line, $page, "The page dropped: {$line}");
            $this->assertStringContainsString($line, $modal, "The modal dropped: {$line}");
        }
    }

    public function test_the_modal_and_the_page_index_the_same_sections(): void
    {
        $modal = $this->get(route('login'))->assertOk()->getContent();
        $page = $this->get($this->plainUrl())->assertOk()->getContent();

        $anchors = static function (string $html): array {
            preg_match_all('/<section id="([a-z-]+)"/', $html, $matches);

            return $matches[1];
        };

        $this->assertNotEmpty($anchors($page));
        $this->assertSame($anchors($page), $anchors($modal));

        // Every entry in the contents has somewhere to land.
        foreach ($anchors($modal) as $id) {
            $this->assertStringContainsString('href="#'.$id.'"', $modal, "Nothing points at #{$id}.");
        }
    }

    public function test_the_page_is_still_reachable_on_its_own(): void
    {
        $this->get($this->plainUrl())
            ->assertOk()
            // The page, not the dialog: the standalone copy must not have been
            // turned into a second rendering of the modal.
            ->assertSee('<main class="legal-doc" id="policy">', false)
            ->assertDontSee('data-privacy-modal', false);
    }

    public function test_a_guest_asking_for_the_notice_is_shown_it_over_the_sign_in_form(): void
    {
        $this->get(route('privacy-policy'))
            ->assertRedirect(route('login', ['privacy' => 1]));

        $this->get(route('login', ['privacy' => 1]))
            ->assertOk()
            // Rendered open by the server, so a reader who asked for the
            // notice sees the notice and not a sign-in form they did not want
            // — with or without JavaScript.
            ->assertSee('data-privacy-autoopen', false)
            ->assertSee('Republic Act No. 10173');
    }

    public function test_the_sign_in_form_is_not_covered_by_the_notice_unasked(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data-privacy-modal', false)
            ->assertDontSee('data-privacy-autoopen', false);
    }

    /**
     * Someone already signed in has no sign-in form to read the notice over,
     * so the redirect would land them on /login and be bounced to their
     * dashboard, having never seen it.
     */
    public function test_a_signed_in_reader_gets_the_document_itself(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('privacy-policy'))
            ->assertOk()
            ->assertSee('<main class="legal-doc" id="policy">', false);
    }

    public function test_the_modals_own_link_reaches_a_page_and_not_another_modal(): void
    {
        $modal = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'href="'.route('privacy-policy', ['plain' => 1]).'"',
            $modal,
            'The way out of the modal has to bypass the redirect, or it opens another modal.'
        );
    }
}
