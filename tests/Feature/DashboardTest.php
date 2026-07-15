<?php

namespace Tests\Feature;

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
            ->assertSee('sidebar-collapse-grip', false)
            ->assertSee('title="Dashboard"', false)
            ->assertSee(route('profile.show'), false)
            ->assertSee(route('settings.edit'), false);
    }
}
