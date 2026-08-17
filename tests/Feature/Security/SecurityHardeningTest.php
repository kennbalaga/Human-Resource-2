<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
