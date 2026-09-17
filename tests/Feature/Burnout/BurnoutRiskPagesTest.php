<?php

namespace Tests\Feature\Burnout;

use App\Models\BurnoutRiskSnapshot;
use App\Models\Employee;
use App\Models\User;
use App\Services\Burnout\BurnoutRiskService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BurnoutRiskPagesTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-09-17 02:00:00';

    private User $hrManager;

    private User $departmentHead;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
        $this->seed();
        $this->hrManager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->departmentHead = User::query()->where('email', 'nursing.head@hrms.local')->firstOrFail();
        $this->staff = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
    }

    public function test_an_employee_sees_their_own_level_and_what_drives_it_on_their_dashboard(): void
    {
        $this->assess($this->staff->employee, 'high', 68.4, [
            'weekly_hours' => ['label' => 'Average weekly hours', 'unit' => 'hours', 'value' => 54.5, 'points' => 22.66, 'maximum' => 25],
            'days_since_leave' => ['label' => 'Days since last leave', 'unit' => 'days', 'value' => 180, 'points' => 10, 'maximum' => 10],
            'night_shifts' => ['label' => 'Night shifts', 'unit' => 'shifts', 'value' => 0, 'points' => 0, 'maximum' => 10],
        ]);

        $this->actingAs($this->staff)->get('/dashboard')
            ->assertOk()
            ->assertSee('My workload &amp; rest', false)
            ->assertSee('Burnout risk: High')
            ->assertSee('Average weekly hours: 54.5 hours')
            ->assertSee('Days since last leave: 180 days')
            // A factor adding nothing is not listed as a reason.
            ->assertDontSee('Night shifts: 0 shifts')
            ->assertSee('does not diagnose burnout');
    }

    public function test_hr_managers_and_administrators_see_their_own_card_on_the_organisation_dashboard(): void
    {
        $administrator = User::query()->whereHas('roles', fn ($query) => $query->where('slug', 'system-administrator'))
            ->whereHas('employee')
            ->firstOrFail();

        foreach ([$this->hrManager, $administrator] as $user) {
            $this->assess($user->employee, 'moderate', 44.5, [
                'overtime_hours' => ['label' => 'Overtime', 'unit' => 'hours', 'value' => 12, 'points' => 15, 'maximum' => 20],
            ]);

            $this->actingAs($user)->get('/dashboard')
                ->assertOk()
                // The organisation dashboard, not the staff one.
                ->assertDontSee('My workday')
                ->assertSee('My workload &amp; rest', false)
                ->assertSee('Burnout risk: Moderate')
                ->assertSee('Overtime: 12 hours');

            $this->app['auth']->forgetGuards();
            $this->flushSession();
        }
    }

    public function test_hr_sees_who_is_closest_to_burnout_on_the_dashboard(): void
    {
        $head = $this->departmentHead->employee;
        $this->assess($head, 'high', 81);
        $this->assess($this->staff->employee, 'moderate', 47);

        $this->actingAs($this->hrManager)->get('/dashboard')
            ->assertOk()
            ->assertSee('Closest to burnout')
            ->assertSeeInOrder(['Closest to burnout', $head->full_name, $this->staff->employee->full_name])
            ->assertSee(route('analytics.burnout-risk', ['level' => 'high']), false);
    }

    public function test_a_department_heads_watchlist_stops_at_their_own_unit(): void
    {
        $head = $this->departmentHead->employee;
        $colleague = Employee::query()
            ->where('department_id', $head->department_id)
            ->whereKeyNot($head->id)
            ->where('employment_status', 'active')
            ->firstOrFail();
        $this->assess($colleague, 'high', 90);
        // Higher than anyone in the unit, and in another department.
        $this->assess($this->staff->employee, 'high', 99);

        $this->actingAs($this->departmentHead)->get('/dashboard')
            ->assertOk()
            ->assertSee('Closest to burnout')
            ->assertSee($colleague->full_name)
            ->assertDontSee($this->staff->employee->full_name);
    }

    public function test_staff_never_see_the_watchlist(): void
    {
        $this->assess($this->departmentHead->employee, 'high', 81);

        $this->actingAs($this->staff)->get('/dashboard')
            ->assertOk()
            ->assertSee('My workload &amp; rest', false)
            ->assertDontSee('Closest to burnout')
            ->assertDontSee($this->departmentHead->employee->full_name);
    }

    public function test_hr_sees_every_department_on_the_burnout_tab_highest_risk_first(): void
    {
        $head = $this->departmentHead->employee;
        $this->assess($this->staff->employee, 'moderate', 41);
        $this->assess($head, 'high', 77);

        $this->actingAs($this->hrManager)->get(route('analytics.burnout-risk'))
            ->assertOk()
            ->assertSee('Burnout Risk')
            ->assertSee('Where the strain is')
            ->assertSeeInOrder([$head->full_name, $this->staff->employee->full_name]);

        $this->actingAs($this->hrManager)->get(route('analytics.burnout-risk', ['level' => 'high']))
            ->assertOk()
            ->assertSee($head->full_name)
            ->assertDontSee($this->staff->employee->full_name);
    }

    public function test_the_live_filters_get_the_results_alone_and_a_normal_visit_gets_the_page(): void
    {
        $head = $this->departmentHead->employee;
        $this->assess($head, 'high', 77);
        $this->assess($this->staff->employee, 'moderate', 41);
        $url = route('analytics.burnout-risk', ['level' => 'high']);

        $live = $this->actingAs($this->hrManager)
            ->get($url, ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertHeader('Vary', 'X-Requested-With')
            ->assertSee('data-burnout-count', false)
            ->assertSee($head->full_name)
            ->assertDontSee($this->staff->employee->full_name)
            ->assertSee('High risk');
        // A fragment: no layout, no filter form, no tabs.
        $this->assertStringNotContainsString('<html', $live->getContent());
        $this->assertStringNotContainsString('data-burnout-filters', $live->getContent());

        $this->actingAs($this->hrManager)->get($url)
            ->assertOk()
            ->assertHeader('Vary', 'X-Requested-With')
            ->assertSee('<html', false)
            ->assertSee('data-burnout-filters', false)
            ->assertSee('data-burnout-results', false)
            ->assertSee($head->full_name);
    }

    public function test_a_department_head_sees_only_their_own_unit(): void
    {
        $head = $this->departmentHead->employee;
        $colleague = Employee::query()
            ->where('department_id', $head->department_id)
            ->whereKeyNot($head->id)
            ->where('employment_status', 'active')
            ->firstOrFail();
        $outsider = $this->staff->employee;
        $this->assertNotSame($head->department_id, $outsider->department_id);

        $this->actingAs($this->departmentHead)->get(route('analytics.burnout-risk'))
            ->assertOk()
            ->assertSee($colleague->full_name)
            ->assertDontSee($outsider->full_name);

        // Asking for another department by id comes back empty, not wider.
        $this->actingAs($this->departmentHead)->get(route('analytics.burnout-risk', ['department_id' => $outsider->department_id]))
            ->assertOk()
            ->assertDontSee($outsider->full_name)
            ->assertSee('Nobody matches these filters.');
    }

    public function test_a_system_administrator_sees_only_their_own_burnout_risk(): void
    {
        $administrator = User::query()->whereHas('roles', fn ($query) => $query->where('slug', 'system-administrator'))
            ->whereHas('employee')
            ->firstOrFail();
        $head = $this->departmentHead->employee;
        $this->assess($head, 'high', 81);
        $this->assess($administrator->employee, 'moderate', 40);

        // Their own card, but no watchlist naming anyone else.
        $this->actingAs($administrator)->get('/dashboard')
            ->assertOk()
            ->assertSee('My workload &amp; rest', false)
            ->assertSee('Burnout risk: Moderate')
            ->assertDontSee('Closest to burnout');

        // The overview stays open to them, without the burnout figures or the tab.
        $this->actingAs($administrator)->get(route('analytics.index'))
            ->assertOk()
            ->assertDontSee('High burnout risk')
            ->assertDontSee(route('analytics.burnout-risk'), false);

        $this->actingAs($administrator)->get(route('analytics.burnout-risk'))->assertForbidden();
        $this->actingAs($administrator)
            ->get(route('analytics.burnout-risk'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertForbidden();
    }

    public function test_hr_sees_the_burnout_figures_on_the_overview(): void
    {
        $this->assess($this->departmentHead->employee, 'high', 81);

        $this->actingAs($this->hrManager)->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('High burnout risk')
            ->assertSee(route('analytics.burnout-risk'), false);
    }

    public function test_staff_cannot_open_the_burnout_tab(): void
    {
        $this->actingAs($this->staff)->get(route('analytics.burnout-risk'))->assertForbidden();
    }

    public function test_the_overview_shows_the_count_and_links_to_the_names(): void
    {
        $this->assess($this->departmentHead->employee, 'high', 77);

        $this->actingAs($this->hrManager)->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('High burnout risk')
            ->assertSee(route('analytics.burnout-risk', ['level' => 'high']), false)
            ->assertDontSee($this->departmentHead->employee->full_name);
    }

    public function test_the_gemini_insight_request_carries_no_burnout_figures(): void
    {
        $this->assess($this->departmentHead->employee, 'high', 77);
        config([
            'integrations.gemini.enabled' => true,
            'integrations.gemini.api_key' => 'test-key',
        ]);
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Insight.']]]]],
        ])]);

        $this->actingAs($this->hrManager)->post(route('analytics.ai-insights'));

        // The request did go out, with the analytics in it -- just not these.
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => str_contains($request->body(), 'attendance_rate'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'burnout'));
    }

    /** @param  array<string, array<string, mixed>>  $factors */
    private function assess(Employee $employee, string $level, float $score, array $factors = []): void
    {
        BurnoutRiskSnapshot::query()->updateOrCreate(
            ['employee_id' => $employee->id, 'as_of_date' => app(BurnoutRiskService::class)->today()->toDateString()],
            ['score' => $score, 'previous_score' => $score - 10, 'level' => $level, 'factors' => $factors],
        );
    }
}
