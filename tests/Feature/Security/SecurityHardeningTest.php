<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\Security\ProductionSecurityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_responses_include_report_only_csp_without_changing_the_page(): void
    {
        config(['security.content_security_policy.mode' => 'report-only']);

        $this->get('/login')
            ->assertOk()
            ->assertHeader('Content-Security-Policy-Report-Only')
            ->assertSee('Welcome Back');
    }

    /**
     * The attendance QR scanner reads badges from a live camera. A policy of
     * camera=() blocks that before the browser ever prompts anyone, and the
     * symptom — no camera entry at all in the site's permissions — reads like a
     * device fault rather than a header, so it is worth holding in place.
     */
    public function test_the_permissions_policy_lets_this_origin_use_a_camera_but_no_one_else(): void
    {
        $policy = $this->get('/login')->assertOk()->headers->get('Permissions-Policy');

        $this->assertStringContainsString('camera=(self)', $policy);
        $this->assertStringContainsString('microphone=()', $policy);
    }

    /**
     * The policy is only worth having if it actually blocks. 'unsafe-inline' in
     * script-src would permit any <script> an attacker injected — the exact
     * thing the policy exists to stop — so its absence is asserted directly
     * rather than left to the reviewer to notice.
     */
    public function test_the_script_policy_carries_a_nonce_instead_of_allowing_inline_script(): void
    {
        config(['security.content_security_policy.mode' => 'enforce']);

        $response = $this->get('/login')->assertOk();
        $policy = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($policy, 'Enforce mode must send the enforcing header, not the report-only one.');

        preg_match('/script-src ([^;]+)/', $policy, $matches);
        $scriptSrc = $matches[1] ?? '';

        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        $this->assertMatchesRegularExpression("/'nonce-[A-Za-z0-9+\/=]+'/", $scriptSrc);
    }

    public function test_every_inline_script_carries_the_nonce_from_the_header(): void
    {
        config(['security.content_security_policy.mode' => 'enforce']);

        $this->seed();
        $user = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", (string) $response->headers->get('Content-Security-Policy'), $matches);
        $nonce = $matches[1] ?? null;
        $this->assertNotNull($nonce);

        // Any <script> without a src must name this request's nonce, or the
        // browser drops it and the page silently loses its behaviour.
        preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*>/i', $response->getContent(), $tags);

        $this->assertNotEmpty($tags[0], 'The layout has an inline bootstrap script; if that changed, this test should too.');

        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$nonce.'"', $tag);
        }
    }

    /**
     * Inline event handlers are script in an attribute. A nonce cannot cover
     * one, so a single `onclick=` anywhere in the templates would force
     * 'unsafe-inline' back into the policy and undo the test above.
     */
    public function test_no_template_uses_an_inline_event_handler(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (preg_match('/\bon(?:click|submit|change|input|load|error|focus|blur|key\w+|mouse\w+)\s*=\s*"/i', $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Use data-confirm (see resources/js/confirm-actions.js) instead of an inline handler.');
    }

    public function test_the_production_check_treats_a_report_only_policy_as_a_failure(): void
    {
        config(['security.content_security_policy.mode' => 'report-only']);

        $failures = array_column(app(ProductionSecurityChecker::class)->criticalFailures(), 'name');

        $this->assertContains('Enforced Content Security Policy', $failures);
    }

    public function test_csp_reports_are_accepted_without_authentication(): void
    {
        $this->postJson('/api/v1/security/csp-report', [
            'csp-report' => [
                'violated-directive' => "script-src 'self'",
                'blocked-uri' => 'https://example.invalid/script.js',
                'source-file' => 'https://localhost/login',
            ],
        ])->assertNoContent();
    }

    public function test_production_guard_is_opt_in_and_blocks_insecure_configuration_when_enabled(): void
    {
        $previousEnvironment = $this->app->environment();
        $this->app->detectEnvironment(fn () => 'production');
        config(['security.production.enforce' => true]);

        try {
            $this->get('/login')->assertServiceUnavailable();
        } finally {
            $this->app->detectEnvironment(fn () => $previousEnvironment);
        }
    }
}
