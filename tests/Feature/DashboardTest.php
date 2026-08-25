<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_dashboard_summary(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $response = $this->actingAs($user)->get('/dashboard');

        // The panel lists one page of the newest employees. Which employees
        // those are is fixture data, so the row assertions follow the page the
        // dashboard actually built rather than naming a number that reseeding
        // could move onto page two.
        $recentEmployees = $response->viewData('recentEmployees');
        $listed = $recentEmployees->first();

        $response
            ->assertOk()
            ->assertSee('HRMS Overview')
            ->assertSee('Total employees')
            ->assertSee('Recently added employees')
            ->assertSee('Workforce by department')
            ->assertSee($listed->employee_number)
            ->assertSee('data-sidebar-collapse', false)
            ->assertSee('data-sidebar-label="Collapse sidebar"', false)
            ->assertSee('aria-label="Collapse sidebar"', false)
            ->assertSee('sidebar-brand-toggle', false)
            ->assertSee('Dr. Jose Rodriguez')
            ->assertDontSee('sidebar-section-label')
            // Integrations and audit logs are no longer buried in Account
            // settings; they sit in their own sidebar group, gated to the roles
            // that administer them (IntegrationController::authorizeIntegrationAdmin()
            // and AuditLogController::authorizeAuditAccess()). An HR manager
            // holds that gate, so the group belongs on this page — the staff
            // case below is what proves the gate still closes.
            ->assertSee('>Integrations<', false)
            ->assertSee('>Audit logs<', false)
            ->assertDontSee('sidebar-user', false)
            ->assertSee('data-topbar-clock', false)
            ->assertSee('data-timezone="Asia/Manila"', false)
            ->assertSee('data-topbar-time', false)
            ->assertSee('data-topbar-date', false)
            ->assertSee('title="Dashboard"', false)
            ->assertSee(route('profile.show'), false)
            ->assertSee(route('settings.edit'), false)
            ->assertSee('data-bs-toggle="dropdown"', false)
            ->assertSee('data-session-timeout', false)
            ->assertSee('data-timeout-seconds="1800"', false)
            ->assertSee('data-warning-seconds="300"', false)
            ->assertSee(route('session.keep-alive'), false)
            ->assertSee('Are you still working?')
            ->assertSee('data-session-login', false);

        $this->assertSame(5, $recentEmployees->perPage());
        $this->assertSame(Employee::query()->count(), $recentEmployees->total());

        // One action menu per listed employee, plus the department panel menu.
        $this->assertSame(
            $recentEmployees->count() + 1,
            substr_count($response->getContent(), 'data-dashboard-action-menu')
        );
        $response
            ->assertSee(route('employees.index'), false)
            ->assertSee(route('attendance.reports.index'), false)
            ->assertSee(route('departments.index'), false)
            ->assertSee(route('positions.index'), false)
            ->assertSee(route('schedules.index', ['employee_id' => $listed->id]), false)
            ->assertSee(route('attendance.reports.index', ['employee_id' => $listed->id]), false)
            ->assertSee(route('leaves.index', ['employee_id' => $listed->id]), false);
    }

    public function test_recently_added_employees_panel_shows_five_per_page(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->assertGreaterThan(5, Employee::query()->count());

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk()->assertSee('employees_page=2', false);
        $this->assertSame(5, $response->viewData('recentEmployees')->count());

        $secondPage = $this->actingAs($user)->get('/dashboard?employees_page=2');

        $secondPage->assertOk();
        $this->assertSame(2, $secondPage->viewData('recentEmployees')->currentPage());
        $this->assertGreaterThan(0, $secondPage->viewData('recentEmployees')->count());
    }

    /**
     * The org-wide overview is a management tool, so a standard employee is routed
     * to the staff dashboard instead. StaffDashboardTest covers what they get; this
     * asserts only that the manager view is not what they are handed.
     */
    public function test_standard_employees_do_not_receive_the_organisation_overview(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('My workday')
            ->assertDontSee('HRMS Overview')
            ->assertDontSee('Recently added employees')
            ->assertDontSee('Workforce by department')
            ->assertDontSee('data-dashboard-action-menu', false)
            // The administration group is role-gated, so a standard employee
            // gets neither entry in the sidebar.
            ->assertDontSee('>Integrations<', false)
            ->assertDontSee('>Audit logs<', false);
    }
}
