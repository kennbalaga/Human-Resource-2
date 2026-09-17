<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ConfirmsDownloadPassword;
use Tests\TestCase;

class AuditLogPageTest extends TestCase
{
    use ConfirmsDownloadPassword, RefreshDatabase;

    public function test_staff_without_a_compliance_role_cannot_read_the_trail(): void
    {
        $this->seed();

        $this->actingAs(User::query()->where('email', 'employee@hrms.local')->firstOrFail())
            ->get('/audit-logs')
            ->assertForbidden();
    }

    public function test_the_page_classifies_events_by_module_activity_and_outcome(): void
    {
        $this->seed();
        $reviewer = $this->reviewer();
        $this->record($reviewer, ['action' => 'employees.update', 'method' => 'PUT', 'path' => 'employees/75', 'response_status' => 302]);
        $this->record(null, ['action' => 'post:login', 'method' => 'POST', 'path' => 'login', 'response_status' => 403]);

        $response = $this->actingAs($reviewer)->get('/audit-logs');

        $response->assertOk()
            ->assertSee('Employee Records')
            ->assertSee('Record updated')
            ->assertSee('Sign-in activity')
            ->assertSee('Blocked')
            ->assertSee('Read-only');

        // The counters describe the whole filtered set, not the visible page.
        $summary = $response->viewData('summary');
        $this->assertSame(1, $summary['attention']);
        $this->assertSame(1, $summary['changes']);
    }

    public function test_each_filter_narrows_the_trail(): void
    {
        $this->seed();
        $reviewer = $this->reviewer();
        $this->record($reviewer, ['action' => 'employees.update', 'method' => 'PUT', 'path' => 'employees/75', 'ip_address' => '10.0.0.9', 'response_status' => 302]);
        $this->record($reviewer, ['action' => 'leaves.approve', 'method' => 'POST', 'path' => 'leaves/4/approve', 'ip_address' => '127.0.0.1', 'response_status' => 200]);
        $this->record(null, ['action' => 'post:login', 'method' => 'POST', 'path' => 'login', 'ip_address' => '127.0.0.1', 'response_status' => 403]);

        $this->assertSame(['employees/75'], $this->pathsFor($reviewer, ['module' => 'employees']));
        $this->assertSame(['login'], $this->pathsFor($reviewer, ['module' => 'authentication']));
        $this->assertSame(['leaves/4/approve'], $this->pathsFor($reviewer, ['activity' => 'approval']));
        $this->assertSame(['employees/75'], $this->pathsFor($reviewer, ['activity' => 'update']));
        $this->assertSame(['login'], $this->pathsFor($reviewer, ['status' => 'unauthorized']));

        // A POST into an authentication path is a sign-in, never a creation.
        $this->assertSame(['leaves/4/approve'], $this->pathsFor($reviewer, ['activity' => 'create']));
    }

    public function test_the_user_filter_offers_whoever_appears_in_the_trail(): void
    {
        $this->seed();
        $reviewer = $this->reviewer();

        // Somebody who has left: their events are in the table, so the filter
        // has to be able to reach them.
        $departed = User::query()->where('email', 'nursing.head@hrms.local')->firstOrFail();
        $departed->forceFill(['is_active' => false])->save();
        $this->record($departed, ['action' => 'employees.update', 'method' => 'PUT', 'path' => 'employees/75', 'response_status' => 302]);

        // Somebody still employed who has never triggered a write: offering them
        // is a filter that can only ever return nothing.
        $silent = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $offered = $this->actingAs($reviewer)->get('/audit-logs')->assertOk()->viewData('users');

        $this->assertTrue($offered->contains('id', $departed->id));
        $this->assertFalse($offered->contains('id', $silent->id));

        $this->assertSame(
            ['employees/75'],
            $this->pathsFor($reviewer, ['user_id' => (string) $departed->id]),
        );
    }

    public function test_active_filters_are_listed_with_a_link_that_drops_each_one(): void
    {
        $this->seed();
        $reviewer = $this->reviewer();

        $active = $this->actingAs($reviewer)
            ->get('/audit-logs?module=employees&status=success')
            ->assertOk()
            ->assertSee('Clear all filters')
            ->viewData('activeFilters');

        $this->assertCount(2, $active);
        $this->assertSame(['status' => 'success'], $active[0]['query']);
        $this->assertSame(['module' => 'employees'], $active[1]['query']);
    }

    public function test_export_honours_the_chosen_scope_and_confirms_it_started(): void
    {
        $this->seed();
        $reviewer = $this->reviewer();
        $this->record($reviewer, ['action' => 'employees.update', 'method' => 'PUT', 'path' => 'employees/75', 'response_status' => 302]);
        $this->record(null, ['action' => 'post:login', 'method' => 'POST', 'path' => 'login', 'response_status' => 403]);

        $filtered = $this->actingAs($reviewer)->get('/audit-logs/export?module=employees&export_token=abc123');
        $filtered->assertOk();
        $this->assertStringStartsWith('text/csv', $filtered->headers->get('Content-Type'));

        $body = $filtered->streamedContent();
        $this->assertStringContainsString('Employee Records', $body);
        $this->assertStringNotContainsString('login', $body);

        // The page cannot hear a streamed download finish, so the token it sent
        // comes back as a cookie to confirm the file is on its way.
        $filtered->assertCookie('audit_export_token');

        $all = $this->actingAs($reviewer)->get('/audit-logs/export?module=employees&scope=all');
        $this->assertStringContainsString('login', $all->streamedContent());
    }

    public function test_the_export_never_carries_submitted_values_or_credentials(): void
    {
        $this->seed();
        $reviewer = $this->reviewer();
        $this->record($reviewer, [
            'action' => 'profile.security.update',
            'method' => 'PUT',
            'path' => 'profile/security',
            'response_status' => 302,
            'metadata' => ['input_fields' => ['name', 'email'], 'request_id' => 'req-1'],
        ]);

        $body = $this->actingAs($reviewer)->get('/audit-logs/export')->streamedContent();

        foreach (['password', 'token', 'two_factor_code', 'recovery_code'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    /**
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    private function pathsFor(User $reviewer, array $filters): array
    {
        return $this->actingAs($reviewer)
            ->get('/audit-logs?'.http_build_query($filters))
            ->assertOk()
            ->viewData('logs')
            ->pluck('path')
            ->all();
    }

    private function reviewer(): User
    {
        return User::query()->where('email', 'admin@hrms.local')->firstOrFail();
    }

    /** @param  array<string, mixed>  $attributes */
    private function record(?User $user, array $attributes): void
    {
        AuditLog::query()->create($attributes + [
            'user_id' => $user?->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'metadata' => ['input_fields' => [], 'request_id' => null],
        ]);
    }
}
