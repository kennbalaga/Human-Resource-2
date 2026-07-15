<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_hr_manager_can_view_analytics_charts(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)->get('/analytics')
            ->assertOk()
            ->assertSee('Workforce Analytics')
            ->assertSee('Attendance trend')
            ->assertSee('Workforce performance');
    }

    public function test_hr_manager_can_download_analytics_csv(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)->get('/analytics/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_standard_employee_cannot_view_analytics(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($employee)->get('/analytics')->assertForbidden();
    }
}
