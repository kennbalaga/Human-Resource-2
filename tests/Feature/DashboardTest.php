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

        $response
            ->assertOk()
            ->assertSee('HRMS Overview')
            ->assertSee('Total employees')
            ->assertSee('Recently added employees')
            ->assertSee('Workforce by department')
            ->assertSee('HR-0001')
            ->assertSee('data-sidebar-collapse', false)
            ->assertSee('data-sidebar-label="Collapse sidebar"', false)
            ->assertSee('aria-label="Collapse sidebar"', false)
            ->assertSee('sidebar-brand-toggle', false)
            ->assertSee('Dr. Jose Rodriguez')
            ->assertDontSee('sidebar-section-label')
            ->assertDontSee('>Integrations<', false)
            ->assertDontSee('>Audit Logs<', false)
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

        $employee = Employee::query()->where('employee_number', 'HR-0002')->firstOrFail();

        $this->assertSame(5, substr_count($response->getContent(), 'data-dashboard-action-menu'));
        $response
            ->assertSee(route('employees.index'), false)
            ->assertSee(route('attendance.reports.index'), false)
            ->assertSee(route('departments.index'), false)
            ->assertSee(route('positions.index'), false)
            ->assertSee(route('schedules.index', ['employee_id' => $employee->id]), false)
            ->assertSee(route('attendance.reports.index', ['employee_id' => $employee->id]), false)
            ->assertSee(route('leaves.index', ['employee_id' => $employee->id]), false);
    }

    public function test_standard_employee_dashboard_actions_use_non_manager_pages(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $response = $this->actingAs($user)->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('Open my attendance')
            ->assertSee('Open my schedule')
            ->assertSee('Open my leave requests')
            ->assertDontSee('Open attendance reports')
            ->assertDontSee('Open shift templates');

        $this->assertSame(5, substr_count($response->getContent(), 'data-dashboard-action-menu'));
    }
}
