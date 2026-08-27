<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyPolicyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_policy_is_readable_without_signing_in(): void
    {
        $this->get(route('privacy-policy'))
            ->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('Republic Act No. 10173');
    }

    public function test_every_auth_page_links_to_the_policy_instead_of_a_dead_anchor(): void
    {
        $href = 'href="'.route('privacy-policy').'" class="privacy"';

        $pages = [
            route('login'),
            route('password.request'),
            route('password.reset', ['token' => 'preview-token']),
        ];

        foreach ($pages as $page) {
            $this->get($page)
                ->assertOk()
                ->assertSee($href, false)
                // The whole point of the change: the footer link used to go
                // nowhere, and a "#" here would mean it silently went back.
                ->assertDontSee('<a href="#" class="privacy">', false)
                ->assertSee('All rights reserved.', false);
        }
    }

    public function test_it_lists_every_data_subject_right_under_the_data_privacy_act(): void
    {
        $response = $this->get(route('privacy-policy'))->assertOk();

        foreach ([
            'Right to be informed',
            'Right to access',
            'Right to rectification',
            'Right to erasure or blocking',
            'Right to object',
            'Right to data portability',
            'Right to damages',
            'Right to file a complaint',
        ] as $right) {
            $response->assertSee($right);
        }
    }

    public function test_it_prints_the_configured_data_protection_officer(): void
    {
        config([
            'privacy.policy.dpo_name' => 'Maria Santos',
            'privacy.policy.dpo_email' => 'dpo@example.test',
            'privacy.policy.dpo_phone' => '(02) 8123 4567',
        ]);

        $this->get(route('privacy-policy'))
            ->assertOk()
            ->assertSee('Maria Santos')
            ->assertSee('dpo@example.test')
            ->assertSee('(02) 8123 4567');
    }

    public function test_it_never_invents_a_contact_address_when_none_is_configured(): void
    {
        config([
            'privacy.policy.dpo_name' => null,
            'privacy.policy.dpo_email' => null,
            'privacy.policy.dpo_phone' => null,
        ]);

        $this->get(route('privacy-policy'))
            ->assertOk()
            ->assertDontSee('mailto:', false)
            ->assertSee('Requests may be filed in person');
    }

    public function test_it_does_not_claim_automatic_deletion_that_is_switched_off(): void
    {
        // Shipping default: every retention window is null, so nothing prunes.
        config(['privacy.retention_days' => [
            'attendance_records' => null,
            'leave_requests' => null,
            'audit_logs' => null,
        ]]);

        $this->get(route('privacy-policy'))
            ->assertOk()
            ->assertSee('automatic deletion is not yet switched on');

        config(['privacy.retention_days.attendance_records' => 1095]);

        $this->get(route('privacy-policy'))
            ->assertOk()
            ->assertSee('deleted automatically by a scheduled maintenance')
            ->assertDontSee('automatic deletion is not yet switched on');
    }
}
