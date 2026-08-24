<?php

namespace Tests\Feature\Organization;

use App\Models\Employee;
use App\Models\User;
use App\Services\Organization\OrgChartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgChartPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('organization.chart'))->assertRedirect('/login');
    }

    /**
     * Deliberately a rank-and-file employee, not a manager: the chart matches
     * the employee directory's access model, which is open to every signed-in
     * user. If that ever tightens, this test is where it will surface.
     */
    public function test_any_authenticated_employee_can_view_the_chart(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($employee)
            ->get(route('organization.chart'))
            ->assertOk()
            ->assertSee('Reporting lines');
    }

    public function test_the_chart_renders_a_seeded_supervisor_and_their_report(): void
    {
        $report = Employee::query()->whereNotNull('supervisor_id')->with('supervisor')->firstOrFail();

        $this->actingAs(User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail())
            ->get(route('organization.chart'))
            ->assertOk()
            ->assertSee($report->full_name)
            ->assertSee($report->supervisor->full_name);
    }

    /**
     * The whole point of the ReportingLineSeeder: without it the chart is a
     * few hundred orphans. This asserts the seeded workforce forms one tree
     * with nobody stranded.
     */
    public function test_seeded_data_forms_a_single_connected_hierarchy(): void
    {
        $chart = app(OrgChartService::class)->tree();

        $this->assertCount(1, $chart['roots']);
        $this->assertSame(0, $chart['unplaced']);
        $this->assertGreaterThan(1, $chart['roots'][0]['direct_reports']);
    }
}
